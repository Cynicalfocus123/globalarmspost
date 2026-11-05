// Makes AVIF files off the main thread with the jSquash AVIF encoder (WebAssembly, Apache-2.0, see vendor/).
import factory from './vendor/avif_enc.js';

let mod = null;
const options = {
  quality: 50, qualityAlpha: -1, denoiseLevel: 0, tileColsLog2: 0, tileRowsLog2: 0, speed: 8, subsample: 1,
  chromaDeltaQ: false, sharpness: 0, tune: 0, enableSharpYUV: false, bitDepth: 8, lossless: false,
};

self.onmessage = async ({ data }) => {
  try {
    mod ??= factory({ noInitialRun: true });
    const m = await mod;
    const out = m.encode(new Uint8Array(data.buf), data.width, data.height, options);
    if (!out) throw new Error('AVIF encoding failed');
    const copy = out.slice(); // own buffer, safe to hand over
    self.postMessage({ id: data.id, ok: true, buf: copy.buffer }, [copy.buffer]);
  } catch (e) {
    self.postMessage({ id: data.id, ok: false, error: String(e?.message || e) });
  }
};
