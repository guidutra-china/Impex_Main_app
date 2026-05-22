// Compress an image File/Blob via canvas. Returns a Blob (JPEG) preserving the
// original filename via the returned wrapper object. Non-image inputs return as-is.

const MAX_DIMENSION = 1600;
const QUALITY = 0.75;

/**
 * @param {File|Blob} file
 * @returns {Promise<{ blob: Blob, filename: string, type: string }>}
 */
export async function compressImage(file) {
    if (!file) return null;
    const originalName = file.name || 'upload.jpg';
    const type = file.type || 'image/jpeg';
    if (!type.startsWith('image/')) {
        return { blob: file, filename: originalName, type };
    }

    let bitmap;
    try {
        bitmap = await createImageBitmap(file);
    } catch {
        // Fallback: return original — better than failing the whole capture.
        return { blob: file, filename: originalName, type };
    }

    const { width, height } = bitmap;
    const longest = Math.max(width, height);
    const scale = longest > MAX_DIMENSION ? MAX_DIMENSION / longest : 1;
    const targetW = Math.round(width * scale);
    const targetH = Math.round(height * scale);

    const canvas = document.createElement('canvas');
    canvas.width = targetW;
    canvas.height = targetH;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(bitmap, 0, 0, targetW, targetH);
    bitmap.close?.();

    const blob = await new Promise((resolve) => {
        canvas.toBlob((b) => resolve(b), 'image/jpeg', QUALITY);
    });

    if (!blob) {
        return { blob: file, filename: originalName, type };
    }

    return {
        blob,
        filename: replaceExtension(originalName, 'jpg'),
        type: 'image/jpeg',
    };
}

function replaceExtension(name, newExt) {
    const dot = name.lastIndexOf('.');
    if (dot === -1) return `${name}.${newExt}`;
    return `${name.slice(0, dot)}.${newExt}`;
}
