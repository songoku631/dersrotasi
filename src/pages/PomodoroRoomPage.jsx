import {
  BookOpen,
  Headphones,
  ImagePlus,
  Lock,
  LogOut,
  MessageCircle,
  Mic,
  MicOff,
  Music,
  Pause,
  Play,
  Settings,
  SkipForward,
  UserX,
  Users,
  VolumeX,
  X,
} from "lucide-react";
import { useCallback, useEffect, useRef, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import Button from "../components/Button";
import Container from "../components/Container";
import UserAvatar from "../components/user/UserAvatar";
import { useAuth } from "../context/useAuth";
import {
  addMusic,
  getRoom,
  getRoomMessages,
  getRoomImage,
  heartbeat,
  leaveRoom,
  moderate,
  musicAction,
  sendRoomImage,
  sendRoomMessage,
  timerAction,
} from "../api/pomodoroApi";

function PrivateChatImage({ user, roomId, message, onPreview }) {
  const [src, setSrc] = useState("");
  useEffect(() => {
    const controller = new AbortController();
    let objectUrl = "";
    getRoomImage(user, roomId, message.id, controller.signal).then((blob) => {
      objectUrl = URL.createObjectURL(blob);
      setSrc(objectUrl);
    }).catch(() => {});
    return () => { controller.abort(); if (objectUrl) URL.revokeObjectURL(objectUrl); };
  }, [message.id, roomId, user]);
  if (!src) return null;
  return <button className="chat-image" onClick={() => onPreview(src)}><img src={src} alt={`${message.username} tarafından gönderilen görsel`} /></button>;
}
import {
  canSpeakInRoom,
  formatTimer,
  roomSeconds,
  supportedMusicUrl,
} from "../utils/pomodoro";
import { usePomodoroVoice } from "../hooks/usePomodoroVoice";

const phaseText = (phase) =>
  phase === "work"
    ? "Çalışma"
    : phase === "break"
      ? "Mola"
      : phase === "paused"
        ? "Duraklatıldı"
        : "Hazır";
const messageTime = (value) =>
  new Intl.DateTimeFormat("tr-TR", {
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(`${String(value).replace(" ", "T")}Z`));

function PomodoroRoomPage() {
  const { roomId } = useParams();
  const { user } = useAuth();
  const navigate = useNavigate();
  const receivedAt = useRef(0);
  const version = useRef(0);
  const active = useRef(true);
  const lastMessage = useRef(0);
  const chatEnd = useRef(null);
  const [now, setNow] = useState(() => performance.now());
  const [busy, setBusy] = useState(false);
  const [room, setRoom] = useState(null);
  const [error, setError] = useState("");
  const [musicUrl, setMusicUrl] = useState("");
  const [deafened, setDeafened] = useState(false);
  const [settingsOpen, setSettingsOpen] = useState(false);
  const [sideTab, setSideTab] = useState("chat");
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [messages, setMessages] = useState([]);
  const [draft, setDraft] = useState("");
  const [sending, setSending] = useState(false);
  const [unread, setUnread] = useState(0);
  const [imageFile, setImageFile] = useState(null);
  const [preview, setPreview] = useState(null);
  const load = useCallback(async () => {
    const request = ++version.current;
    try {
      const r = await getRoom(user, roomId);
      if (!active.current || request !== version.current) return;
      receivedAt.current = performance.now();
      setNow(receivedAt.current);
      setRoom(r.data.room);
      setError("");
    } catch (e) {
      if (active.current && request === version.current) setError(e.message);
    }
  }, [roomId, user]);
  useEffect(() => {
    active.current = true;
    load();
    const sync = setInterval(load, 3000);
    const tick = setInterval(() => setNow(performance.now()), 250);
    const visible = () => {
      if (!document.hidden) load();
    };
    document.addEventListener("visibilitychange", visible);
    return () => {
      active.current = false;
      clearInterval(sync);
      clearInterval(tick);
      document.removeEventListener("visibilitychange", visible);
    };
  }, [load]);
  useEffect(() => {
    let alive = true;
    const poll = async () => {
      try {
        const r = await getRoomMessages(user, roomId, lastMessage.current);
        const items = r.data.items || [];
        if (!alive || !items.length) return;
        lastMessage.current = Math.max(
          lastMessage.current,
          ...items.map((item) => item.id),
        );
        setMessages((current) => [
          ...current,
          ...items.filter(
            (item) => !current.some((existing) => existing.id === item.id),
          ),
        ]);
        if (
          sideTab !== "chat" ||
          (!drawerOpen && matchMedia("(max-width: 900px)").matches)
        )
          setUnread((value) => value + items.length);
      } catch {
        /* room refresh reports membership errors */
      }
    };
    poll();
    const id = setInterval(poll, 2500);
    return () => {
      alive = false;
      clearInterval(id);
    };
  }, [drawerOpen, roomId, sideTab, user]);
  useEffect(() => {
    if (
      sideTab === "chat" &&
      (drawerOpen || !matchMedia("(max-width: 900px)").matches)
    ) {
      setUnread(0);
      chatEnd.current?.scrollIntoView({ block: "end" });
    }
  }, [drawerOpen, messages, sideTab]);
  const seconds = roomSeconds(room, now - receivedAt.current);
  const canSpeak = canSpeakInRoom(
    room,
    seconds,
    now - receivedAt.current < 10000,
  );
  const voice = usePomodoroVoice({
    user,
    roomId,
    members: room?.members || [],
    enabled: room?.voice_enabled,
    canSpeak,
    deafened,
  });
  const micEnabled = canSpeak && voice.status === "connected" && !voice.muted;
  const hasRoom = Boolean(room);
  useEffect(() => {
    if (!hasRoom) return;
    const beat = () => heartbeat(user, roomId, micEnabled).catch(() => {});
    beat();
    const id = setInterval(beat, 15000);
    return () => clearInterval(id);
  }, [hasRoom, micEnabled, roomId, user]);
  async function timer(action, body) {
    if (busy) return;
    setBusy(true);
    ++version.current;
    try {
      await timerAction(user, roomId, action, body);
      await load();
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }
  async function leave() {
    voice.leave();
    await leaveRoom(user, roomId).catch(() => {});
    navigate("/pomodoro");
  }
  async function queue(event) {
    event.preventDefault();
    if (!supportedMusicUrl(musicUrl))
      return setError("Yalnızca YouTube ve Spotify bağlantıları desteklenir.");
    try {
      await addMusic(user, roomId, musicUrl);
      setMusicUrl("");
      await load();
    } catch (e) {
      setError(e.message);
    }
  }
  async function submitMessage(event) {
    event.preventDefault();
    const message = draft.trim();
    if ((!message && !imageFile) || sending || message.length > 1000) return;
    setSending(true);
    try {
      const r = imageFile
        ? await sendRoomImage(user, roomId, imageFile, message)
        : await sendRoomMessage(user, roomId, message);
      const item = r.data.message;
      lastMessage.current = Math.max(lastMessage.current, item.id);
      setMessages((current) =>
        current.some((existing) => existing.id === item.id)
          ? current
          : [...current, item],
      );
      setDraft("");
      setImageFile(null);
    } catch (e) {
      setError(e.message);
    } finally {
      setSending(false);
    }
  }
  function chooseImage(event) {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file) return;
    if (
      !["image/jpeg", "image/png", "image/webp"].includes(file.type) ||
      file.size > 5 * 1024 * 1024
    )
      return setError(
        "JPEG, PNG veya WebP biçiminde en fazla 5 MB görsel seç.",
      );
    setImageFile(file);
    setError("");
  }
  function toggleDeafen() {
    const next = !deafened;
    setDeafened(next);
    document.querySelectorAll("[data-pomodoro-audio]").forEach((audio) => {
      audio.muted = next || audio.dataset.locallyMuted === "true" || !canSpeak;
    });
  }
  function openPanel(tab) {
    setSideTab(tab);
    setDrawerOpen(true);
    if (tab === "chat") setUnread(0);
  }
  if (!room)
    return (
      <section className="pomodoro-page">
        <Container>
          {error ? (
            <div className="pomo-alert">{error}</div>
          ) : (
            <p>Oda hazırlanıyor…</p>
          )}
          <Button to="/pomodoro" variant="secondary">
            Odalara dön
          </Button>
        </Container>
      </section>
    );
  const owner = room.is_owner;
  const phase = room.current_phase;
  const duration =
    (phase === "break" ||
    (phase === "paused" && room.phase_before_pause === "break")
      ? room.break_minutes
      : room.work_minutes) * 60;
  const progress = Math.max(0, Math.min(100, 100 * (1 - seconds / duration)));
  const statusLine =
    phase === "break" && room.voice_enabled
      ? `Mola • Sesli sohbet açık • ${formatTimer(seconds)} kaldı`
      : `${phaseText(phase)} • ${formatTimer(seconds)} kaldı`;
  return (
    <section className="pomodoro-page room-page">
      <Container>
        <div className={`room-app ${drawerOpen ? "room-app--drawer" : ""}`}>
          <aside className="room-rail">
            <div className="room-rail__identity">
              <strong>{room.name}</strong>
              <span>
                {room.category}
                {room.password_protected && <Lock size={12} />}
              </span>
            </div>
            <nav aria-label="Oda bölümleri">
              <button className="is-active">
                <BookOpen />
                Çalışma Odası
              </button>
              <button
                onClick={() =>
                  document
                    .querySelector(".room-voice-controls")
                    ?.scrollIntoView()
                }
              >
                <Headphones />
                Sesli Sohbet
              </button>
              <button onClick={() => openPanel("chat")}>
                <MessageCircle />
                Metin Sohbeti{unread > 0 && <b>{unread > 9 ? "9+" : unread}</b>}
              </button>
            </nav>
            {owner && (
              <button
                className="room-rail__settings"
                onClick={() => setSettingsOpen((value) => !value)}
              >
                <Settings />
                Oda ayarları
              </button>
            )}
            <div className="room-rail__meta">
              {room.member_count}/{room.max_members} çevrimiçi
            </div>
          </aside>
          <main className="room-stage">
            <header className="room-stage__header">
              <div>
                <span className="room-stage__eyebrow">
                  {room.category} ·{" "}
                  {room.visibility === "private" ? "Özel oda" : "Herkese açık"}
                </span>
                <h1>{room.name}</h1>
                <p>{statusLine}</p>
              </div>
              {owner && (
                <div className="room-host-controls">
                  <button
                    disabled={busy}
                    onClick={() =>
                      timer(
                        phase === "idle"
                          ? "start"
                          : phase === "paused"
                            ? "resume"
                            : "pause",
                      )
                    }
                  >
                    {phase === "idle" || phase === "paused" ? (
                      <Play />
                    ) : (
                      <Pause />
                    )}
                    {phase === "idle"
                      ? "Başlat"
                      : phase === "paused"
                        ? "Devam"
                        : "Duraklat"}
                  </button>
                  <button disabled={busy} onClick={() => timer("next")}>
                    <SkipForward />
                    Sonraki tur
                  </button>
                </div>
              )}
            </header>
            {error && <div className="pomo-alert room-alert">{error}</div>}
            <section className="room-timer">
              <div className={`phase-badge phase--${phase}`}>
                {phaseText(phase)}
              </div>
              <div
                className="room-timer__clock"
                role="timer"
                aria-label="Kalan süre"
              >
                {formatTimer(seconds)}
              </div>
              <div
                className="timer-progress"
                role="progressbar"
                aria-label="Tur ilerlemesi"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={Math.round(progress)}
              >
                <span style={{ width: `${progress}%` }} />
              </div>
              <small>
                {room.cycle_number}. tur · {room.work_minutes} dk odak /{" "}
                {room.break_minutes} dk mola
              </small>
            </section>
            <section className="room-people" aria-label="Odadaki kullanıcılar">
              {room.members.map((member) => {
                const speaking =
                  canSpeak &&
                  (member.user_key === user.uid
                    ? micEnabled
                    : member.microphone_enabled);
                return (
                  <article
                    className={`voice-member ${speaking ? "is-speaking" : ""}`}
                    key={member.user_key}
                  >
                    <div className="voice-member__avatar">
                      <UserAvatar
                        size={62}
                        profile={{ username: member.username }}
                        profilePhotoUrl={member.profile_photo_url}
                      />
                      {!speaking && (
                        <span>
                          <MicOff />
                        </span>
                      )}
                    </div>
                    <strong>
                      @{member.username}
                      {member.user_key === user.uid && <em> (Sen)</em>}
                    </strong>
                    {member.role === "owner" ? (
                      <small className="host-tag">Host</small>
                    ) : (
                      <small>
                        {speaking ? "Konuşuyor" : "Mikrofon kapalı"}
                      </small>
                    )}
                  </article>
                );
              })}
            </section>
            {settingsOpen && owner && (
              <section className="room-settings">
                <form
                  key={`${room.work_minutes}-${room.break_minutes}`}
                  onSubmit={(event) => {
                    event.preventDefault();
                    timer("durations", {
                      work_minutes: +event.currentTarget.work.value,
                      break_minutes: +event.currentTarget.break.value,
                    });
                  }}
                >
                  <label>
                    Çalışma{" "}
                    <input
                      name="work"
                      type="number"
                      min="5"
                      max="180"
                      defaultValue={room.work_minutes}
                    />
                  </label>
                  <label>
                    Mola{" "}
                    <input
                      name="break"
                      type="number"
                      min="1"
                      max="60"
                      defaultValue={room.break_minutes}
                    />
                  </label>
                  <button disabled={busy}>Süreleri kaydet</button>
                </form>
                {room.music_enabled && (
                  <div className="room-music">
                    <form onSubmit={queue}>
                      <input
                        aria-label="YouTube veya Spotify bağlantısı"
                        placeholder="YouTube veya Spotify bağlantısı"
                        value={musicUrl}
                        onChange={(e) => setMusicUrl(e.target.value)}
                      />
                      <button>
                        <Music />
                        Sıraya ekle
                      </button>
                    </form>
                    {room.music_queue.map((track) => (
                      <a
                        key={track.id}
                        href={track.external_url}
                        target="_blank"
                        rel="noreferrer"
                      >
                        {track.title || `${track.provider} parçası`}
                      </a>
                    ))}
                    {room.music_queue.length > 0 && (
                      <div>
                        <button
                          onClick={() =>
                            musicAction(user, roomId, "skip").then(load)
                          }
                        >
                          Geç
                        </button>
                        <button
                          onClick={() =>
                            musicAction(user, roomId, "clear").then(load)
                          }
                        >
                          Temizle
                        </button>
                      </div>
                    )}
                  </div>
                )}
              </section>
            )}
            <div className="room-mobile-tabs">
              <button onClick={() => openPanel("chat")}>
                <MessageCircle />
                Sohbet{unread > 0 && <b>{unread}</b>}
              </button>
              <button onClick={() => openPanel("members")}>
                <Users />
                Üyeler
              </button>
            </div>
            <div className="room-voice-controls">
              <button
                title="Mikrofon"
                disabled={!canSpeak || voice.status === "connecting"}
                className={micEnabled ? "is-on" : ""}
                onClick={() =>
                  voice.status === "connected"
                    ? voice.toggleMute()
                    : voice.join()
                }
              >
                {micEnabled ? <Mic /> : <MicOff />}
                <span>
                  {voice.status === "connected"
                    ? voice.muted
                      ? "Mikrofonu aç"
                      : "Mikrofonu kapat"
                    : canSpeak
                      ? "Sesliye katıl"
                      : "Sesli sohbet kapalı"}
                </span>
              </button>
              <button
                title="Gelen sesi kapat"
                className={deafened ? "is-on" : ""}
                onClick={toggleDeafen}
              >
                {deafened ? <VolumeX /> : <Headphones />}
                <span>{deafened ? "Sesi aç" : "Sesi kapat"}</span>
              </button>
              <button
                title="Ayarlar"
                onClick={() => setSettingsOpen((value) => !value)}
              >
                <Settings />
                <span>Ayarlar</span>
              </button>
              <button className="leave-control" onClick={leave}>
                <LogOut />
                <span>Odadan çık</span>
              </button>
            </div>
          </main>
          <aside className="room-side">
            <header className="room-chat-header">
              <div><strong>{room.name}</strong><span>{statusLine}</span></div>
            </header>
            <div className="room-side__tabs">
              <button
                className={sideTab === "chat" ? "is-active" : ""}
                onClick={() => {
                  setSideTab("chat");
                  setUnread(0);
                }}
              >
                Sohbet{unread > 0 && <b>{unread}</b>}
              </button>
              <button
                className={sideTab === "members" ? "is-active" : ""}
                onClick={() => setSideTab("members")}
              >
                Üyeler
              </button>
              <button
                className="room-side__close"
                aria-label="Paneli kapat"
                onClick={() => setDrawerOpen(false)}
              >
                ×
              </button>
            </div>
            {sideTab === "chat" ? (
              <>
                <div className="room-chat" aria-live="polite">
                  {messages.length === 0 && (
                    <div className="room-chat__empty">
                      <MessageCircle />
                      <strong>Sohbeti başlat</strong>
                      <span>Mesajlar yalnızca bu odada görünür.</span>
                    </div>
                  )}
                  {messages.map((message) => (
                    <article className="chat-message" key={message.id}>
                      <UserAvatar
                        size={34}
                        profile={{ username: message.username }}
                        profilePhotoUrl={message.profile_photo_url}
                      />
                      <div>
                        <header>
                          <strong>@{message.username}</strong>
                          <time>{messageTime(message.created_at)}</time>
                        </header>
                        {message.attachment_path && <PrivateChatImage user={user} roomId={roomId} message={message} onPreview={setPreview} />}
                        {message.message && <p>{message.message}</p>}
                      </div>
                    </article>
                  ))}
                  <div ref={chatEnd} />
                </div>
                {imageFile && <div className="chat-upload-preview"><ImagePlus /><span>{imageFile.name}</span><button onClick={() => setImageFile(null)}><X /></button></div>}
                <form className="chat-composer" onSubmit={submitMessage}>
                  <label className="chat-attach" title="Fotoğraf ekle"><ImagePlus /><input type="file" accept="image/jpeg,image/png,image/webp" onChange={chooseImage} /></label>
                  <textarea
                    aria-label="Mesaj gönder"
                    placeholder="Mesaj gönder…"
                    maxLength={1000}
                    rows={1}
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === "Enter" && !e.shiftKey) {
                        e.preventDefault();
                        e.currentTarget.form.requestSubmit();
                      }
                    }}
                  />
                  <small>{draft.length}/1000</small>
                  <button
                    disabled={(!draft.trim() && !imageFile) || sending}
                    aria-label="Mesajı gönder"
                  >
                    ↑
                  </button>
                </form>
              </>
            ) : (
              <div className="side-members">
                <h2>Çevrimiçi — {room.member_count}</h2>
                {room.members.map((member) => (
                  <div className="side-member" key={member.user_key}>
                    <UserAvatar
                      size={38}
                      profile={{ username: member.username }}
                      profilePhotoUrl={member.profile_photo_url}
                    />
                    <div>
                      <strong>
                        @{member.username}
                        {member.user_key === user.uid && " (Sen)"}
                      </strong>
                      <small>
                        {member.role === "owner"
                          ? "Host"
                          : member.microphone_enabled
                            ? "Mikrofon açık"
                            : "Mikrofon kapalı"}
                      </small>
                    </div>
                    {owner && member.role !== "owner" && (
                      <button
                        title="Odadan çıkar"
                        onClick={() =>
                          moderate(user, roomId, "kick", member.user_key).then(
                            load,
                          )
                        }
                      >
                        <UserX />
                      </button>
                    )}
                  </div>
                ))}
              </div>
            )}
          </aside>
          <button
            className="room-drawer-backdrop"
            aria-label="Paneli kapat"
            onClick={() => setDrawerOpen(false)}
          />
          {preview && <div className="chat-lightbox" role="dialog" aria-modal="true" aria-label="Görsel önizleme" onClick={() => setPreview(null)}><button aria-label="Kapat"><X /></button><img src={preview} alt="Sohbet görseli önizlemesi" onClick={(event) => event.stopPropagation()} /></div>}
        </div>
      </Container>
    </section>
  );
}
export default PomodoroRoomPage;
