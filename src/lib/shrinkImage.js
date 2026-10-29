/**
 * Makes a photo web-sized before upload: longest side at most `max` px, re-encoded.
 * A 6 MB phone photo usually becomes 200–400 KB with no visible difference, which keeps
 * uploads fast on mobile data and the database small. Falls back to the original file
 * if the browser can't decode or encode it.
 */
export async function shrinkImage(file, { max = 1600, quality = 0.82 } = {}) {
  let bitmap
  try {
    bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' })
  } catch {
    return file
  }
  const scale = Math.min(1, max / Math.max(bitmap.width, bitmap.height))
  if (scale === 1 && file.size < 400 * 1024) {
    bitmap.close()
    return file
  }
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(bitmap.width * scale)
  canvas.height = Math.round(bitmap.height * scale)
  canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height)
  bitmap.close()

  // JPEG for photos; WebP for PNG/WebP so logos keep their transparent background.
  const type = file.type === 'image/jpeg' ? 'image/jpeg' : 'image/webp'
  const blob = await new Promise((resolve) => canvas.toBlob(resolve, type, quality))
  if (!blob || blob.size >= file.size) return file
  const ext = blob.type === 'image/jpeg' ? 'jpg' : blob.type === 'image/webp' ? 'webp' : 'png'
  return new File([blob], file.name.replace(/\.\w+$/, '') + '.' + ext, { type: blob.type })
}
