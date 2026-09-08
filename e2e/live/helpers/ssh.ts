/** Command execution on the test site over SSH. */
import { execFileSync } from 'node:child_process';
import { SITE } from '../site';

export interface SshOptions {
  /** Fail the call when the remote command exits non-zero. Defaults to true. */
  check?: boolean;
  timeoutMs?: number;
}

/** Runs a shell command in the WordPress root of the test site. */
export function ssh(command: string, options: SshOptions = {}): string {
  const { check = true, timeoutMs = 60_000 } = options;
  const args = [
    '-i', SITE.sshKey,
    '-o', 'IdentitiesOnly=yes',
    '-o', 'BatchMode=yes',
    SITE.sshHost,
    `cd ${SITE.wpRoot} && ${command}`,
  ];

  try {
    return execFileSync('ssh', args, { encoding: 'utf8', timeout: timeoutMs });
  } catch (error) {
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
