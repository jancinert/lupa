import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

const [configurationFile, outputFile] = process.argv.slice(2);
const configuration = JSON.parse(await readFile(configurationFile, 'utf8'));
const url = new URL(configuration.url);
const requests = [];
const baseFetch = globalThis.fetch;
const checkedFetch = async (input, init) => {
	const response = await baseFetch(input, { ...init, redirect: 'error', signal: AbortSignal.timeout(20000) });
	assert.equal(response.headers.get('set-cookie'), null);
	assert.ok((response.headers.get('cache-control') ?? '').split(',').some(value => value.trim().toLowerCase() === 'no-store'));
	requests.push({ method: init?.method ?? 'GET', status: response.status });
	return response;
};
const transport = new StreamableHTTPClientTransport(url, {
	requestInit: { headers: { Authorization: `Bearer ${configuration.token}` } },
	fetch: checkedFetch,
});
const client = new Client({ name: 'lupa-regression-client', version: '1.0.0' }, { capabilities: {} });
let result;
try {
	await client.connect(transport);
	const listed = await client.listTools();
	assert.deepEqual(listed.tools.map(tool => tool.name).sort(), ['app_info', 'db_env', 'db_relations', 'db_table', 'db_tables']);
	const info = await client.callTool({ name: 'app_info', arguments: {} });
	assert.equal(info.isError, false);
	assert.equal(info.structuredContent.application.framework, 'none');
	if (configuration.expectedPhp) {
		assert.equal(info.structuredContent.php.version, configuration.expectedPhp.version);
		assert.equal(info.structuredContent.php.extensions.includes('mbstring'), configuration.expectedPhp.mbstring);
	}
	assert.ok(!info.structuredContent.inspector.tools.includes('db_query'));
	const tables = await client.callTool({ name: 'db_tables', arguments: {} });
	assert.equal(tables.isError, false);
	assert.ok(tables.structuredContent.tables.some(table => table.name === 'products'));
	const detail = await client.callTool({ name: 'db_table', arguments: { table: 'users' } });
	assert.equal(detail.isError, false);
	assert.ok(!JSON.stringify(detail).includes('synthetic-http-secret'));
	const passwordColumn = detail.structuredContent.columns.find(column => column.name === 'password_hash');
	assert.ok(passwordColumn);
	assert.ok(passwordColumn.default === null || passwordColumn.default === '***');
	const maskedError = await client.callTool({ name: 'app_info', arguments: { 'password=synthetic-http-client-secret': 'unused' } });
	assert.equal(maskedError.isError, true);
	assert.ok(!JSON.stringify(maskedError).includes('synthetic-http-client-secret'));
	assert.ok(JSON.stringify(maskedError).includes('***'));
	const nestedError = await client.callTool({ name: 'app_info', arguments: { unused: 'p' + '['.repeat(4000) + 'x' + ']'.repeat(4000) } });
	assert.equal(nestedError.isError, true);
	const collidingArguments = Object.fromEntries(Array.from({ length: 4000 }, (_, i) => [`token=${i}`, 0]));
	const collisionError = await client.callTool({ name: 'app_info', arguments: collidingArguments });
	assert.equal(collisionError.isError, true);
	await client.ping();
	for (const name of ['db_relations', 'db_env']) {
		const response = await client.callTool({ name, arguments: {} });
		assert.equal(response.isError, false);
		assert.ok(!JSON.stringify(response).includes(configuration.token));
	}
	let refused = false;
	try {
		const response = await client.callTool({ name: 'db_query', arguments: { sql: 'SELECT id FROM products' } });
		refused = response.isError === true;
	} catch (error) {
		refused = error.code === -32602;
	}
	assert.equal(refused, true);
	const headers = {
		Authorization: `Bearer ${configuration.token}`,
		'Content-Type': 'application/json',
		Accept: 'application/json, text/event-stream',
		'MCP-Protocol-Version': '2025-11-25',
	};
	const ping = JSON.stringify({ jsonrpc: '2.0', id: 9, method: 'ping' });
	const unauthorized = await baseFetch(url, { method: 'POST', headers: { ...headers, Authorization: 'Bearer invalid' }, body: ping, redirect: 'error' });
	assert.equal(unauthorized.status, 401);
	assert.ok(!(await unauthorized.text()).includes(configuration.token));
	const origin = await baseFetch(url, { method: 'POST', headers: { ...headers, Origin: 'https://attacker.test' }, body: ping, redirect: 'error' });
	assert.equal(origin.status, 403);
	const batch = await baseFetch(url, { method: 'POST', headers, body: `[${ping}]`, redirect: 'error' });
	assert.equal(batch.status, 400);
	const oversized = await baseFetch(url, { method: 'POST', headers, body: 'x'.repeat(65537), redirect: 'error' });
	assert.equal(oversized.status, 413);
	for (const method of ['GET', 'DELETE']) {
		const response = await baseFetch(url, { method, headers, redirect: 'error' });
		assert.equal(response.status, 405);
	}
	const session = await baseFetch(url, { method: 'POST', headers: { ...headers, 'Mcp-Session-Id': 'unsupported' }, body: ping, redirect: 'error' });
	assert.equal(session.status, 400);
	result = { ok: true, client: '@modelcontextprotocol/sdk', transport: 'Streamable HTTP', php: info.structuredContent.php.version, framework: 'none', tools: listed.tools.map(tool => tool.name), tableCount: tables.structuredContent.count, passwordDefaultAbsent: passwordColumn.default === null, checks: { authenticated: true, structuredResults: true, allAdvertisedTools: true, schemaMasking: true, syntheticErrorMasking: true, nestedInvalidArgumentRefused: true, collidingArgumentNamesRefused: true, subsequentPing: true, sqlRefused: true, unauthenticatedRefused: true, foreignOriginRefused: true, batchRefused: true, bodyLimit: true, unsupportedMethodsRefused: true, sessionsRefused: true, noCookies: true, noStore: true }, requests };
} finally {
	await client.close();
}
await writeFile(outputFile, JSON.stringify(result, null, 2));
console.log(JSON.stringify({ ok: result.ok, client: result.client, php: result.php, tableCount: result.tableCount }));
