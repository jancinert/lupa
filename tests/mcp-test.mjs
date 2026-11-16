import assert from 'node:assert/strict';
import { randomBytes, createHash } from 'node:crypto';
import { mkdir, mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { createServer } from 'node:net';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn, execFileSync } from 'node:child_process';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';

const tests = dirname(fileURLToPath(import.meta.url));
const root = dirname(tests);
const php = process.argv[2] ?? 'php';
process.umask(0o077);
await mkdir(join(tests, 'tmp'), { recursive: true });
const temporary = await mkdtemp(join(tests, 'tmp', 'mcp-'));
const project = join(temporary, 'project');
const token = randomBytes(32).toString('hex');
const env = { ...process.env, LUPA_HTTP_TEST_PROJECT: project, LUPA_HTTP_TEST_HASH: createHash('sha256').update(token).digest('hex') };
const expectedPhp = JSON.parse(execFileSync(php, ['-r', 'echo json_encode(["version" => PHP_VERSION, "mbstring" => extension_loaded("mbstring")]);'], { env, windowsHide: true, encoding: 'utf8' }));

async function run(command, args) {
	return new Promise((resolve, reject) => {
		const process = spawn(command, args, { cwd: root, env, stdio: 'inherit', windowsHide: true });
		process.once('error', reject);
		process.once('exit', code => code === 0 ? resolve() : reject(new Error(`Test process exited with ${code}`)));
	});
}

await run(php, [join(tests, 'fixtures/http/setup.php')]);
const databaseBefore = createHash('sha256').update(await readFile(join(project, 'app.sqlite'))).digest('hex');
const client = new Client({ name: 'lupa-stdio-regression', version: '1.0.0' });
const transport = new StdioClientTransport({
	command: php,
	args: [join(root, 'bin/lupa'), 'serve', '--project', project, '--autoload', join(root, 'vendor/autoload.php')],
	env,
	stderr: 'pipe',
});
try {
	await client.connect(transport);
	const tools = await client.listTools();
	assert.ok(tools.tools.some(tool => tool.name === 'db_query'));
	assert.ok(!tools.tools.some(tool => tool.name === 'render'));
	const info = await client.callTool({ name: 'app_info', arguments: {} });
	assert.equal(info.isError, false);
	assert.equal(info.structuredContent.application.framework, 'none');
	assert.equal(info.structuredContent.php.version, expectedPhp.version);
	assert.equal(info.structuredContent.php.extensions.includes('mbstring'), expectedPhp.mbstring);
	const result = await client.callTool({ name: 'db_query', arguments: { sql: 'SELECT id, name FROM products' } });
	assert.equal(result.isError, false);
	assert.equal(result.structuredContent.rowCount, 1);
	const refused = await client.callTool({ name: 'db_query', arguments: { sql: 'DELETE FROM products' } });
	assert.equal(refused.isError, true);
	const detail = await client.callTool({ name: 'db_table', arguments: { table: 'users' } });
	assert.equal(detail.isError, false);
	assert.equal(detail.structuredContent.columns.find(column => column.name === 'password_hash').default, '***');
	await client.ping();
} finally {
	await client.close();
}

const listener = createServer();
await new Promise((resolve, reject) => { listener.once('error', reject); listener.listen(0, '127.0.0.1', resolve); });
const port = listener.address().port;
await new Promise(resolve => listener.close(resolve));
const url = `http://127.0.0.1:${port}/`;
const configuration = join(temporary, 'client.json');
await writeFile(configuration, JSON.stringify({ url, token, expectedPhp }), { mode: 0o600 });
const server = spawn(php, ['-d', 'disable_functions=proc_open', '-S', `127.0.0.1:${port}`, join(tests, 'fixtures/http/endpoint.php')], {
	cwd: root, env, stdio: 'ignore', windowsHide: true,
});
const stopped = new Promise(resolve => { server.once('exit', resolve); server.once('error', resolve); });
let startError;
server.once('error', error => { startError = error; });
try {
	let ready = false;
	for (let attempt = 0; attempt < 100; attempt++) {
		if (startError) throw startError;
		if (server.exitCode !== null) throw new Error('HTTP fixture exited before becoming ready');
		try {
			const response = await fetch(url, { signal: AbortSignal.timeout(500) });
			if (response.status === 401) { ready = true; break; }
		} catch { /* the server is starting */ }
		await new Promise(resolve => setTimeout(resolve, 100));
	}
	assert.ok(ready, 'HTTP fixture must become ready');
	await run(process.execPath, [join(tests, 'http-client.mjs'), configuration, join(temporary, 'http-results.json')]);
} finally {
	server.kill();
	await stopped;
	await writeFile(configuration, '{}');
}
assert.equal(createHash('sha256').update(await readFile(join(project, 'app.sqlite'))).digest('hex'), databaseBefore);
console.log(`MCP stdio and HTTP clients passed on PHP ${expectedPhp.version}, mbstring=${expectedPhp.mbstring}; SQLite is unchanged.`);
