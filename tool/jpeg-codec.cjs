'use strict';
const jpeg = require('jpeg-js');
let chunks = [], length = 0;
process.stdin.on('data', chunk => {
  length += chunk.length;
  if (length > 307200) process.exit(2);
  chunks.push(chunk);
});
process.stdin.on('end', () => {
  try {
    const decoded = jpeg.decode(Buffer.concat(chunks), {
      useTArray: true, tolerantDecoding: false,
      maxResolutionInMP: 4.194304, maxMemoryUsageInMB: 96,
    });
    if (decoded.width < 1 || decoded.height < 1 || decoded.width > 2048 || decoded.height > 2048) process.exit(2);
    // Deliberately do not pass comments/exifBuffer to the encoder.
    const result = jpeg.encode({ width: decoded.width, height: decoded.height, data: decoded.data }, 82).data;
    if (result.length > 307182) process.exit(2);
    process.stdout.write(result);
  } catch (_) { process.exitCode = 2; }
});
