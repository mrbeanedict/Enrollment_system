// Offline blur detection for uploaded document images — no internet, no
// paid API, no server image library needed (this app's PHP install has
// neither GD nor Imagick enabled). Runs entirely in the browser via
// <canvas>: downscale the image, convert to grayscale, convolve with a
// Laplacian edge-detection kernel, and measure how much the result
// varies. A sharp image has strong, varied edges (high variance); a
// blurry one is flat (low variance). This is a classic, well-known but
// approximate heuristic, not a perfect classifier — a very plain,
// low-detail but genuinely sharp document could occasionally score low.
// BLUR_THRESHOLD is a starting point and may need tuning against real
// sample documents.
(function (global) {
  'use strict';

  var BLUR_THRESHOLD = 100;
  var MAX_DIMENSION = 300; // downscale target — keeps the canvas work fast

  // Loads `source` (a File/Blob, or an image URL string) into an <img>,
  // resolving once it's ready to draw. Object URLs are revoked after load.
  function loadImage(source) {
    return new Promise(function (resolve, reject) {
      var img = new Image();
      var objectUrl = null;

      if (typeof source !== 'string') {
        objectUrl = URL.createObjectURL(source);
        img.src = objectUrl;
      } else {
        img.crossOrigin = 'anonymous';
        img.src = source;
      }

      img.onload = function () {
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        resolve(img);
      };
      img.onerror = function () {
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        reject(new Error('Could not load image for blur check.'));
      };
    });
  }

  // Returns a Promise<number> — the Laplacian variance (sharpness score).
  // Higher = sharper, lower = blurrier.
  function scoreImageSharpness(source) {
    return loadImage(source).then(function (img) {
      var scale = Math.min(1, MAX_DIMENSION / Math.max(img.naturalWidth, img.naturalHeight));
      var w = Math.max(1, Math.round(img.naturalWidth * scale));
      var h = Math.max(1, Math.round(img.naturalHeight * scale));

      var canvas = document.createElement('canvas');
      canvas.width = w;
      canvas.height = h;
      var ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0, w, h);

      var imageData;
      try {
        imageData = ctx.getImageData(0, 0, w, h);
      } catch (e) {
        // Canvas got tainted (cross-origin image without proper CORS
        // headers) — can't read pixel data. Treat as "can't determine,"
        // not "blurry," so it never blocks a legitimate upload.
        return Infinity;
      }
      var data = imageData.data;

      // Grayscale, single channel, row-major.
      var gray = new Float32Array(w * h);
      for (var i = 0, p = 0; i < data.length; i += 4, p++) {
        gray[p] = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
      }

      // Laplacian kernel: [[0,1,0],[1,-4,1],[0,1,0]] — skip the 1px border.
      var sum = 0;
      var sumSq = 0;
      var count = 0;
      for (var y = 1; y < h - 1; y++) {
        for (var x = 1; x < w - 1; x++) {
          var idx = y * w + x;
          var lap = gray[idx - w] + gray[idx + w] + gray[idx - 1] + gray[idx + 1] - 4 * gray[idx];
          sum += lap;
          sumSq += lap * lap;
          count++;
        }
      }
      if (count === 0) return Infinity;

      var mean = sum / count;
      var variance = (sumSq / count) - (mean * mean);
      return variance;
    });
  }

  global.scoreImageSharpness = scoreImageSharpness;
  global.BLUR_THRESHOLD = BLUR_THRESHOLD;
})(window);
