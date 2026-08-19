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

/*
 * Requests are processed strictly in order. It costs a little concurrency but
 * guarantees initialize completes, and its session id is captured, before
 * anything that depends on it goes out.
 */
let queue = Promise.resolve();

function enqueue(message) {
	queue = queue
		.then(() => post(message))
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
