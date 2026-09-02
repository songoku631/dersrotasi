import { RotateCcw, X } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import Button from '../Button'

const CROP_SIZE = 280
const OUTPUT_SIZE = 768

function clampOffset(value, imageSize) {
  const limit = Math.max(0, (imageSize - CROP_SIZE) / 2)
  return Math.max(-limit, Math.min(limit, value))
}

function ProfilePhotoCropModal({ file, onCancel, onSave }) {
  const imageRef = useRef(null)
  const cropCanvasRef = useRef(null)
  const previewCanvasRef = useRef(null)
  const dragRef = useRef(null)
  const [imageSize, setImageSize] = useState(null)
  const [zoom, setZoom] = useState(1)
  const [offset, setOffset] = useState({ x: 0, y: 0 })
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState('')
  const objectUrl = useMemo(() => URL.createObjectURL(file), [file])

  useEffect(() => () => URL.revokeObjectURL(objectUrl), [objectUrl])
  useEffect(() => {
    function closeOnEscape(event) { if (event.key === 'Escape' && !saving) onCancel() }
    document.addEventListener('keydown', closeOnEscape)
    return () => document.removeEventListener('keydown', closeOnEscape)
  }, [onCancel, saving])

  const baseScale = imageSize ? Math.max(CROP_SIZE / imageSize.width, CROP_SIZE / imageSize.height) : 1
  const displayed = imageSize ? { width: imageSize.width * baseScale * zoom, height: imageSize.height * baseScale * zoom } : { width: CROP_SIZE, height: CROP_SIZE }

  useEffect(() => {
    const image = imageRef.current
    const cropCanvas = cropCanvasRef.current
    const previewCanvas = previewCanvasRef.current
    if (!image || !imageSize || !cropCanvas || !previewCanvas) return

    function draw(context, size) {
      const scale = size / CROP_SIZE
      const drawWidth = displayed.width * scale
      const drawHeight = displayed.height * scale
      context.clearRect(0, 0, size, size)
      context.fillStyle = '#dbe4f2'
      context.fillRect(0, 0, size, size)
      context.drawImage(image, size / 2 + offset.x * scale - drawWidth / 2, size / 2 + offset.y * scale - drawHeight / 2, drawWidth, drawHeight)
    }

    const cropContext = cropCanvas.getContext('2d')
    draw(cropContext, cropCanvas.width)
    const previewContext = previewCanvas.getContext('2d')
    previewContext.save()
    previewContext.beginPath()
    previewContext.arc(previewCanvas.width / 2, previewCanvas.height / 2, previewCanvas.width / 2, 0, Math.PI * 2)
    previewContext.clip()
    draw(previewContext, previewCanvas.width)
    previewContext.restore()
  }, [displayed.height, displayed.width, imageSize, offset.x, offset.y])

  function recenter() { setZoom(1); setOffset({ x: 0, y: 0 }) }
  function updateZoom(nextZoom) {
    const next = Number(nextZoom)
    const width = imageSize ? imageSize.width * baseScale * next : CROP_SIZE
    const height = imageSize ? imageSize.height * baseScale * next : CROP_SIZE
    setZoom(next)
    setOffset((current) => ({ x: clampOffset(current.x, width), y: clampOffset(current.y, height) }))
  }
  function startDrag(event) {
    event.currentTarget.setPointerCapture(event.pointerId)
    dragRef.current = { pointerId: event.pointerId, x: event.clientX, y: event.clientY, offset }
  }
  function drag(event) {
    const start = dragRef.current
    if (!start || start.pointerId !== event.pointerId) return
    setOffset({
      x: clampOffset(start.offset.x + event.clientX - start.x, displayed.width),
      y: clampOffset(start.offset.y + event.clientY - start.y, displayed.height),
    })
  }
  function stopDrag(event) { if (dragRef.current?.pointerId === event.pointerId) dragRef.current = null }

  async function save() {
    if (!imageRef.current || !imageSize) return
    setSaving(true); setSaveError('')
    try {
      const canvas = document.createElement('canvas')
      canvas.width = OUTPUT_SIZE
      canvas.height = OUTPUT_SIZE
      const context = canvas.getContext('2d')
      const outputScale = OUTPUT_SIZE / CROP_SIZE
      const drawWidth = displayed.width * outputScale
      const drawHeight = displayed.height * outputScale
      context.fillStyle = '#ffffff'
      context.fillRect(0, 0, OUTPUT_SIZE, OUTPUT_SIZE)
      context.drawImage(imageRef.current, OUTPUT_SIZE / 2 + offset.x * outputScale - drawWidth / 2, OUTPUT_SIZE / 2 + offset.y * outputScale - drawHeight / 2, drawWidth, drawHeight)
      const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9))
      if (!blob) throw new Error('Görsel hazırlanamadı. Lütfen tekrar dene.')
      await onSave(new File([blob], 'profil-fotografi.jpg', { type: 'image/jpeg' }))
    } catch (error) {
      setSaveError(error.message || 'Fotoğraf yüklenemedi. Lütfen tekrar dene.')
    } finally { setSaving(false) }
  }

  return (
    <div className="profile-crop-backdrop" role="presentation" onMouseDown={() => { if (!saving) onCancel() }}>
      <section aria-labelledby="profile-crop-title" aria-modal="true" className="profile-crop-modal" role="dialog" onMouseDown={(event) => event.stopPropagation()}>
        <div className="profile-crop-modal__header">
          <div><p className="eyebrow">Profil fotoğrafı</p><h2 id="profile-crop-title">Fotoğrafını ayarla</h2></div>
          <button aria-label="Kapat" className="profile-crop-modal__close" disabled={saving} onClick={onCancel} type="button"><X /></button>
        </div>
        <p>Fotoğrafı sürükleyerek konumlandır, yakınlaştırarak kare alanı doldur.</p>
        <div aria-label="Fotoğraf kırpma alanı" className="profile-crop-stage" onPointerCancel={stopDrag} onPointerDown={startDrag} onPointerMove={drag} onPointerUp={stopDrag}>
          <canvas aria-label="Kırpılacak profil fotoğrafı" height={560} ref={cropCanvasRef} role="img" width={560} />
          <img alt="" aria-hidden="true" className="profile-crop-source" onError={() => setSaveError('Görsel önizlenemedi. Başka bir dosya seç.')} onLoad={(event) => setImageSize({ width: event.currentTarget.naturalWidth, height: event.currentTarget.naturalHeight })} ref={imageRef} src={objectUrl} />
          <div aria-hidden="true" className="profile-crop-mask" />
        </div>
        <div className="profile-crop-controls">
          <label><span>Yakınlaştırma</span><input aria-label="Yakınlaştırma" disabled={!imageSize || saving} max="3" min="1" onChange={(event) => updateZoom(event.target.value)} step="0.01" type="range" value={zoom} /></label>
          <button className="profile-crop-reset" disabled={saving} onClick={recenter} type="button"><RotateCcw size={17} /> Ortala</button>
        </div>
        <div className="profile-crop-preview-row"><span>Yuvarlak önizleme</span><div className="profile-crop-preview"><canvas aria-label="Yuvarlak profil önizlemesi" height={160} ref={previewCanvasRef} role="img" width={160} /></div></div>
        {saveError ? <div className="form-alert" role="alert"><p>{saveError}</p></div> : null}
        <div className="profile-crop-actions"><Button disabled={saving} onClick={onCancel} type="button" variant="secondary">İptal</Button><Button disabled={!imageSize || saving} onClick={save} type="button">{saving ? 'Kaydediliyor...' : 'Kaydet'}</Button></div>
      </section>
    </div>
  )
}

export default ProfilePhotoCropModal
