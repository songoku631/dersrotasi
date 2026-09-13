/* oxlint-disable react-hooks/exhaustive-deps -- peer lifecycle is intentionally keyed by room/user. */
import { useCallback, useEffect, useRef, useState } from "react";
import { getSignals, getVoiceIceConfig, sendSignal } from "../api/pomodoroApi";

export function usePomodoroVoice({
  user,
  roomId,
  members,
  enabled,
  canSpeak = false,
  deafened = false,
}) {
  const [status, setStatus] = useState("idle");
  const [muted, setMuted] = useState(true);
  const [error, setError] = useState("");
  const stream = useRef(null);
  const peers = useRef(new Map());
  const pendingIce = useRef(new Map());
  const after = useRef(0);
  const generation = useRef(0);
  const speakAllowed = useRef(false);
  speakAllowed.current = canSpeak;
  const deafenedRef = useRef(false);
  deafenedRef.current = deafened;
  const iceServers = useRef([
    { urls: ["stun:stun.l.google.com:19302", "stun:stun1.l.google.com:19302"] },
  ]);
  const iceExpiresAt = useRef(0);
  const debug = (event, uid, pc, extra = {}) => {
    if (import.meta.env.DEV)
      console.debug("[PomodoroVoice]", event, {
        peer: uid?.slice(0, 8),
        connectionState: pc?.connectionState,
        iceConnectionState: pc?.iceConnectionState,
        signalingState: pc?.signalingState,
        ...extra,
      });
  };
  const flushIce = async (uid, pc) => {
    const queued = pendingIce.current.get(uid) || [];
    pendingIce.current.delete(uid);
    for (const candidate of queued) await pc.addIceCandidate(candidate);
  };
  const makePeer = useCallback(
    (uid, initiator = false) => {
      if (peers.current.has(uid)) return peers.current.get(uid);
      const pc = new RTCPeerConnection({ iceServers: iceServers.current });
      stream.current
        ?.getTracks()
        .forEach((track) => pc.addTrack(track, stream.current));
      pc.onicecandidate = (event) => {
        if (!event.candidate) return;
        const type = event.candidate.type || event.candidate.candidate.match(/ typ (host|srflx|relay)(?: |$)/)?.[1];
        debug("candidate", uid, pc, { candidateType: type || "unknown" });
        sendSignal(user, roomId, uid, "ice", event.candidate.toJSON()).catch(() => {});
      };
      pc.onconnectionstatechange = () => debug("connection", uid, pc);
      pc.oniceconnectionstatechange = () => debug("ice", uid, pc);
      pc.ontrack = (event) => {
        let audio = document.getElementById(`voice-${uid}`);
        if (!audio) {
          audio = document.createElement("audio");
          audio.id = `voice-${uid}`;
          audio.autoplay = true;
          audio.playsInline = true;
          audio.dataset.pomodoroAudio = "true";
          document.body.appendChild(audio);
        }
        audio.muted =
          deafenedRef.current || audio.dataset.locallyMuted === "true";
        audio.srcObject = event.streams[0];
        audio
          .play()
          .catch((reason) =>
            debug("autoplay-blocked", uid, pc, { name: reason.name }),
          );
        debug("remote-track", uid, pc, {
          tracks: event.streams[0]?.getAudioTracks().length || 0,
        });
      };
      peers.current.set(uid, pc);
      if (initiator)
        pc.createOffer()
          .then((offer) => pc.setLocalDescription(offer))
          .then(() =>
            sendSignal(user, roomId, uid, "offer", pc.localDescription),
          )
          .catch(() => debug("offer-failed", uid, pc));
      return pc;
    },
    [roomId, user],
  );
  const join = useCallback(async () => {
    if (!enabled || !speakAllowed.current || stream.current) return;
    const attempt = generation.current;
    try {
      setError("");
      setStatus("connecting");
      const config = await getVoiceIceConfig(user);
      iceServers.current = config.data.ice_servers;
      iceExpiresAt.current = config.data.expires_at || 0;
      const acquired = await navigator.mediaDevices.getUserMedia({
        audio: { echoCancellation: true, noiseSuppression: true },
        video: false,
      });
      if (attempt !== generation.current) {
        acquired.getTracks().forEach((track) => track.stop());
        return;
      }
      stream.current = acquired;
      stream.current.getAudioTracks().forEach((track) => {
        track.enabled = true;
      });
      debug("local-track", user.uid, null, {
        tracks: stream.current
          .getAudioTracks()
          .map((track) => ({
            enabled: track.enabled,
            readyState: track.readyState,
          })),
      });
      setMuted(false);
      setStatus("connected");
      members
        .filter((member) => member.user_key !== user.uid)
        .forEach((member) =>
          makePeer(member.user_key, user.uid < member.user_key),
        );
    } catch {
      setError("Mikrofon izni verilmedi veya ses bağlantısı kurulamadı.");
      setStatus("error");
    }
  }, [enabled, makePeer, members, user]);
  const leave = useCallback(() => {
    ++generation.current;
    stream.current?.getTracks().forEach((track) => track.stop());
    stream.current = null;
    peers.current.forEach((peer) => peer.close());
    peers.current.clear();
    pendingIce.current.clear();
    document
      .querySelectorAll("[data-pomodoro-audio]")
      .forEach((audio) => audio.remove());
    setStatus("idle");
    setMuted(true);
  }, []);
  const toggleMute = () => {
    const next = !speakAllowed.current || !muted;
    stream.current?.getAudioTracks().forEach((track) => {
      track.enabled = !next;
    });
    setMuted(next);
  };
  useEffect(() => {
    if (status !== "connected" || !iceExpiresAt.current) return;
    const refresh = async () => {
      if (Date.now() / 1000 < iceExpiresAt.current - 600) return;
      try {
        const config = await getVoiceIceConfig(user);
        iceServers.current = config.data.ice_servers;
        iceExpiresAt.current = config.data.expires_at || 0;
        debug("ice-config-refreshed", user.uid, null, { relayAvailable: config.data.relay_available });
      } catch (reason) {
        debug("ice-config-refresh-failed", user.uid, null, { name: reason.name });
      }
    };
    const id = setInterval(refresh, 5 * 60 * 1000);
    return () => clearInterval(id);
  }, [status, user]);
  useEffect(() => {
    if (status !== "connected") return;
    members
      .filter((member) => member.user_key !== user.uid)
      .forEach((member) =>
        makePeer(member.user_key, user.uid < member.user_key),
      );
  }, [makePeer, members, status, user]);
  useEffect(() => {
    if (status !== "connected") return;
    const poll = async () => {
      try {
        const response = await getSignals(user, roomId, after.current);
        for (const signal of response.data.items) {
          const pc = makePeer(signal.sender_uid);
          if (signal.signal_type === "offer") {
            await pc.setRemoteDescription(signal.payload);
            await flushIce(signal.sender_uid, pc);
            const answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);
            await sendSignal(
              user,
              roomId,
              signal.sender_uid,
              "answer",
              pc.localDescription,
            );
          } else if (signal.signal_type === "answer") {
            await pc.setRemoteDescription(signal.payload);
            await flushIce(signal.sender_uid, pc);
          } else if (signal.signal_type === "ice") {
            if (pc.remoteDescription) await pc.addIceCandidate(signal.payload);
            else
              pendingIce.current.set(signal.sender_uid, [
                ...(pendingIce.current.get(signal.sender_uid) || []),
                signal.payload,
              ]);
          }
          after.current = Math.max(after.current, signal.id);
        }
      } catch (reason) {
        if (import.meta.env.DEV)
          console.debug("[PomodoroVoice] signaling-retry", reason.name);
      }
    };
    poll();
    const id = setInterval(poll, 1500);
    return () => clearInterval(id);
  }, [makePeer, roomId, status, user]);
  useEffect(() => {
    document.querySelectorAll("[data-pomodoro-audio]").forEach((audio) => {
      audio.muted = deafened || audio.dataset.locallyMuted === "true";
    });
  }, [deafened]);
  useEffect(() => {
    if (!enabled) leave();
  }, [enabled, leave]);
  useEffect(() => leave, [leave, roomId]);
  return { status, muted, error, join, leave, toggleMute };
}
