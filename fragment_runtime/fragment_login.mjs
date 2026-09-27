import fs from 'node:fs';
import path from 'node:path';

const [, , inputPath, outputPath] = process.argv;

let phone = '';
try {
  const input = JSON.parse(fs.readFileSync(inputPath, 'utf8'));
  phone = String(input.phone || '').trim();
} finally {
  if (inputPath) {
    try { fs.unlinkSync(inputPath); } catch {}
  }
}

function writeState(data) {
  const directory = path.dirname(outputPath || '');
  if (!outputPath || !directory || !fs.existsSync(directory)) {
    throw new Error('Invalid login state path.');
  }
  const temporary = `${outputPath}.${process.pid}.tmp`;
  fs.writeFileSync(temporary, JSON.stringify(data), { mode: 0o600 });
  fs.renameSync(temporary, outputPath);
  fs.chmodSync(outputPath, 0o600);
}

if (!/^\+[1-9]\d{7,14}$/.test(phone || '')) {
  writeState({ status: 'error', error: 'INVALID_PHONE' });
  process.exit(2);
}

try {
  writeState({ status: 'waiting', started_at: new Date().toISOString() });
  const { Fragment } = await import('fragment-tg');
  const fragment = new Fragment({ cookies: { stel_dt: '-210' } });
  await fragment.init();
  await fragment.getSessionCookie();
  const result = await fragment.loginWithPhone(phone, {
    pollInterval: 3000,
    maxAttempts: 80,
  });
  writeState({
    status: 'success',
    completed_at: new Date().toISOString(),
    cookies: result.cookies,
    user: result.userInfo || null,
  });
} catch (error) {
  writeState({
    status: 'error',
    completed_at: new Date().toISOString(),
    error: String(error?.message || error || 'LOGIN_FAILED').slice(0, 500),
  });
  process.exit(1);
}
