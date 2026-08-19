#!/usr/bin/env node
/**
 * FDJ WordPress MCP bridge.
 *
 * Translates MCP stdio (newline-delimited JSON-RPC on stdin/stdout) into HTTP
 * calls against a WordPress MCP Adapter endpoint, authenticated with a
 * WordPress Application Password.
 *
 * Deliberately zero-dependency and built on node:https alone, so the bundle
 * stays a few KB, installs offline, and has no dependency tree to keep patched.
 *
 * Config comes from the environment, which the .mcpb manifest populates from
 * the values the user entered at install time:
 *   WP_API_URL       full URL of the MCP server endpoint
 *   WP_API_USERNAME  WordPress username (user_login, not the display name)
 *   WP_API_PASSWORD  Application Password
 *
 * stdout carries the protocol and NOTHING else. All diagnostics go to stderr.
 */

'use strict';

const http = require('node:http');
const https = require('node:https');
const { URL } = require('node:url');

const ENDPOINT = process.env.WP_API_URL || '';
const USERNAME = process.env.WP_API_USERNAME || '';
const PASSWORD = process.env.WP_API_PASSWORD || '';

function log(...args) {
	process.stderr.write('[fdj-bridge] ' + args.join(' ') + '\n');
}

if (!ENDPOINT || !USERNAME || !PASSWORD) {
	log('Missing configuration. WP_API_URL, WP_API_USERNAME and WP_API_PASSWORD are all required.');
	process.exit(1);
}

let endpointUrl;
try {
	endpointUrl = new URL(ENDPOINT);
} catch (e) {
	log('WP_API_URL is not a valid URL: ' + ENDPOINT);
	process.exit(1);
}

const transport = endpointUrl.protocol === 'http:' ? http : https;
const AUTH = 'Basic ' + Buffer.from(USERNAME + ':' + PASSWORD).toString('base64');

// The adapter hands back a session id on initialize and expects it thereafter.
let sessionId = null;

/*
 * The client's own initialize message, kept so the session can be rebuilt
 * without the client's involvement. See recoverAndRetry() for why that is
 * necessary rather than merely tidy.
 */
let lastInitialize = null;

// McpErrorFactory::SESSION_NOT_FOUND in the WordPress MCP Adapter.
const SESSION_NOT_FOUND = -32005;

/**
 * POST one JSON-RPC message and resolve with the parsed reply, or null when the
 * server returns no body (notifications answer with 202 and nothing else).
 */
function post(message) {
	return new Promise((resolve) => {
		const body = Buffer.from(JSON.stringify(message), 'utf8');

		const headers = {
			'Content-Type': 'application/json',
			'Accept': 'application/json, text/event-stream',
			'Content-Length': body.length,
			'Authorization': AUTH,
			'User-Agent': 'fdj-wp-mcp-bridge',
		};

		if (sessionId) {
			headers['Mcp-Session-Id'] = sessionId;
		}

		const req = transport.request(
			{
				protocol: endpointUrl.protocol,
				hostname: endpointUrl.hostname,
				port: endpointUrl.port || (endpointUrl.protocol === 'http:' ? 80 : 443),
				path: endpointUrl.pathname + endpointUrl.search,
				method: 'POST',
				headers,
			},
			(res) => {
				const returned = res.headers['mcp-session-id'];
				if (returned) {
					sessionId = returned;
				}

				const chunks = [];
				res.on('data', (c) => chunks.push(c));
				res.on('end', () => {
					const raw = Buffer.concat(chunks).toString('utf8').trim();

					if (!raw) {
						resolve(null);
						return;
					}

					if (res.statusCode === 401 || res.statusCode === 403) {
						resolve(errorFor(message, -32001,
							'WordPress rejected the credentials (HTTP ' + res.statusCode + '). Check the username and Application Password, and that the user still exists.'));
						return;
					}

					resolve(parseBody(raw, message, res.statusCode));
				});
			}
		);

		req.on('error', (err) => {
			resolve(errorFor(message, -32002, 'Could not reach ' + endpointUrl.host + ': ' + err.message));
		});

		req.write(body);
		req.end();
	});
}

/**
 * The adapter may answer as plain JSON or as a single SSE frame. Accept both.
 */
function parseBody(raw, message, statusCode) {
	let text = raw;

	if (text.startsWith('event:') || text.startsWith('data:')) {
		text = raw
			.split('\n')
			.filter((line) => line.startsWith('data:'))
			.map((line) => line.slice(5).trim())
			.join('');
	}

	if (!text) {
		return null;
	}

	try {
		return JSON.parse(text);
	} catch (e) {
		return errorFor(message, -32003,
			'Unreadable reply from WordPress (HTTP ' + statusCode + '). This usually means a plugin or security layer injected output into the REST response. First 200 characters: ' + raw.slice(0, 200));
	}
}

function errorFor(message, code, text) {
	if (!message || typeof message.id === 'undefined' || message.id === null) {
		log(text);
		return null;
	}

	return {
		jsonrpc: '2.0',
		id: message.id,
		error: { code, message: text },
	};
}

function send(payload) {
	if (payload) {
		process.stdout.write(JSON.stringify(payload) + '\n');
	}
}

/**
 * Whether a reply is the adapter rejecting our session id.
 *
 * Note this arrives as HTTP 200 with a JSON-RPC error in the body, not as a 4xx,
 * so it has to be matched on the error code.
 */
function isSessionGone(reply) {
	return !!(reply && reply.error && reply.error.code === SESSION_NOT_FOUND);
}

/**
 * Send a message, and if the adapter says our session is gone, transparently
 * rebuild the session and send it once more.
 *
 * Two things make this necessary rather than defensive padding:
 *
 * 1. Sessions expire. The adapter stores them in user meta with a 24 hour
 *    inactivity timeout, so any client left running overnight wakes up with a
 *    session id the server has already dropped.
 *
 * 2. Concurrent first connections race. SessionManager::mutate_sessions() uses
 *    update_user_meta() with a $prev_value guard, but WordPress ignores an empty
 *    $prev_value, so when several clients initialize at once against a user with
 *    no stored sessions they overwrite each other. The adapter documents this in
 *    its own source. Claude Desktop, Cowork and Code each run a separate copy of
 *    this bridge, which is exactly the condition that triggers it: whichever copy
 *    loses the race holds a session id that was never persisted.
 *
 * Recovery is one attempt. If re-initializing does not help, the original error
 * is returned, since it describes the real problem better than a retry failure.
 */
async function postWithRecovery(message) {
	const reply = await post(message);

	// An initialize IS the recovery, so it must never recurse into one.
	if (!isSessionGone(reply) || message.method === 'initialize' || !lastInitialize) {
		return reply;
	}

	log('Session rejected by WordPress. Re-initializing and retrying once.');
	sessionId = null;

	const reinitialized = await post(lastInitialize);

	if (!reinitialized || reinitialized.error || !sessionId) {
		return reply;
	}

	// Deliberately not sent to stdout: the client already has its initialize
	// response and a second one carrying the same id would corrupt the stream.
	await post({ jsonrpc: '2.0', method: 'notifications/initialized' });

	return post(message);
}

/*
 * Requests are processed strictly in order. It costs a little concurrency but
 * guarantees initialize completes, and its session id is captured, before
 * anything that depends on it goes out.
 */
let queue = Promise.resolve();

function enqueue(message) {
	if (message && message.method === 'initialize') {
		lastInitialize = message;
	}

	queue = queue
		.then(() => postWithRecovery(message))
		.then(send)
		.catch((err) => {
			send(errorFor(message, -32603, 'Bridge error: ' + (err && err.message ? err.message : String(err))));
		});
}

let buffer = '';

process.stdin.setEncoding('utf8');

process.stdin.on('data', (chunk) => {
	buffer += chunk;

	let newline;
	while ((newline = buffer.indexOf('\n')) !== -1) {
		const line = buffer.slice(0, newline).trim();
		buffer = buffer.slice(newline + 1);

		if (!line) {
			continue;
		}

		let message;
		try {
			message = JSON.parse(line);
		} catch (e) {
			log('Ignoring unparseable line from client: ' + line.slice(0, 200));
			continue;
		}

		enqueue(message);
	}
});

process.stdin.on('end', () => {
	queue.then(() => process.exit(0));
});
