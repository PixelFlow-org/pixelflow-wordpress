/** Command execution on the test site over SSH. */
import { execFileSync } from 'node:child_process';
import { rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { SITE } from '../site';

/**
 * A run makes hundreds of short SSH calls — one per cart reset, log truncation
 * and log read. Multiplexing them over a single connection avoids paying for a
 * handshake every time and keeps the site from seeing a burst of connections
 * that looks like an attack to a rate limiter.
 *
 * The socket lives in the system temp dir because a unix socket path is capped
 * at around 100 characters, which the scratchpad path alone would exhaust.
 */
const CONTROL_PATH = path.join(tmpdir(), `pf-live-ssh-${process.env.USER ?? 'run'}`);

const CONTROL_ARGS = [
  '-o', 'ControlMaster=auto',
  '-o', `ControlPath=${CONTROL_PATH}`,
  // Outlives the individual calls, so the whole run shares one connection, but
  // does not linger once the run is over.
  '-o', 'ControlPersist=120',
];

export interface SshOptions {
  /** Fail the call when the remote command exits non-zero. Defaults to true. */
  check?: boolean;
  timeoutMs?: number;
}

/** Runs a shell command in the WordPress root of the test site. */
export function ssh(command: string, options: SshOptions = {}): string {
  const { check = true, timeoutMs = 60_000 } = options;
  const args = [
    // Without an explicit key the host's own ~/.ssh/config entry decides which
    // identity to use; forcing one here would override it.
    ...(SITE.sshKey ? ['-i', SITE.sshKey, '-o', 'IdentitiesOnly=yes'] : []),
    '-o', 'BatchMode=yes',
    ...CONTROL_ARGS,
    SITE.sshHost,
    `cd ${SITE.wpRoot} && ${command}`,
  ];

  try {
    return execFileSync('ssh', args, { encoding: 'utf8', timeout: timeoutMs });
  } catch (error) {
    // A stalled master poisons every later call: the socket is there, so ssh
    // waits on it rather than dialling out, and the call dies on our timeout
    // instead of reporting anything. Drop the socket and dial once more before
    // giving up — the retry opens its own connection and becomes the new master.
    if ((error as { code?: string }).code === 'ETIMEDOUT') {
      try {
        rmSync(CONTROL_PATH, { force: true });
      } catch {
        // Nothing to clear; fall through to the retry regardless.
      }

      try {
        return execFileSync('ssh', args, { encoding: 'utf8', timeout: timeoutMs });
      } catch (retryError) {
        error = retryError;
      }
    }

    if (!check) {
      const failure = error as { stdout?: string };
      return failure.stdout ?? '';
    }
    const failure = error as { stderr?: string; message: string };
    throw new Error(`ssh command failed: ${command}\n${failure.stderr || failure.message}`);
  }
}

/** Runs WP-CLI on the test site and returns trimmed stdout. */
export function wp(args: string, options: SshOptions = {}): string {
  return ssh(`${SITE.wpCli} ${args}`, options).trim();
}

/** Runs a PHP snippet through `wp eval` — quoting is handled here so callers write plain PHP. */
export function wpEval(php: string, options: SshOptions = {}): string {
  const oneLine = php.replace(/\s*\n\s*/g, ' ').replace(/'/g, `'\\''`);
  return wp(`eval '${oneLine}'`, options);
}
