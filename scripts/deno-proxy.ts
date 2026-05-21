/**
 * Transparent proxy for the WordPress AI Connector MCP server.
 *
 * Forwards every request to TARGET. For OAuth well-known metadata
 * responses, rewrites URLs in the body so the metadata advertises the
 * proxy's origin (otherwise the metadata would point clients back at the
 * origin host and defeat the purpose). Same for the WWW-Authenticate
 * response header on 401s.
 *
 * Deploy on Deno Deploy:
 *   1. https://dash.deno.com → "+ New Project" → Deploy from GitHub or
 *      "Playground" and paste this file.
 *   2. Set the project's environment variable TARGET to your origin
 *      (e.g. https://ferramentasphda.pt). Optional — defaults to the
 *      hardcoded value below.
 *   3. The deployment URL (something like
 *      https://your-project.deno.dev) is what you give Claude as the
 *      Remote MCP server URL — but suffix it with the path under your
 *      origin where the MCP endpoint lives:
 *        https://your-project.deno.dev/wp-json/wp-ai-connector/v1/mcp
 */

const TARGET = Deno.env.get("TARGET") ?? "https://ferramentasphda.pt";

Deno.serve(async (req: Request): Promise<Response> => {
	const incoming = new URL(req.url);
	const proxyOrigin = incoming.origin; // e.g. https://your-project.deno.dev
	const targetUrl = TARGET + incoming.pathname + incoming.search;

	// Strip headers that aren't safe to forward verbatim. Host is rewritten
	// by fetch automatically; cf-* are CDN-only.
	const forwardHeaders = new Headers(req.headers);
	forwardHeaders.delete("host");
	forwardHeaders.delete("cf-connecting-ip");
	forwardHeaders.delete("cf-ray");
	forwardHeaders.delete("cf-visitor");

	// Buffer the request body before forwarding. Streaming the body through
	// Deno's fetch is flaky without `duplex: "half"`, and the body sizes we
	// see (JSON-RPC payloads, OAuth form data) are tiny.
	const hasBody = !["GET", "HEAD"].includes(req.method);
	const bodyBuffer = hasBody ? await req.arrayBuffer() : undefined;

	let upstream: Response;
	try {
		upstream = await fetch(targetUrl, {
			method: req.method,
			headers: forwardHeaders,
			body: bodyBuffer,
			redirect: "manual",
		});
	} catch (err) {
		return new Response(
			JSON.stringify({ error: "upstream_unreachable", detail: String(err) }),
			{ status: 502, headers: { "content-type": "application/json" } }
		);
	}

	const responseHeaders = new Headers(upstream.headers);
	// Allow Claude to read the discovery hint cross-origin.
	responseHeaders.set("access-control-allow-origin", "*");
	responseHeaders.set(
		"access-control-expose-headers",
		"www-authenticate, mcp-session-id, mcp-protocol-version"
	);

	// Rewrite WWW-Authenticate header so resource_metadata points at us, not origin.
	const wwwAuth = responseHeaders.get("www-authenticate");
	if (wwwAuth) {
		responseHeaders.set(
			"www-authenticate",
			wwwAuth.replaceAll(TARGET, proxyOrigin)
		);
	}

	// Rewrite Location header on redirects so user browsers stay on the proxy.
	const location = responseHeaders.get("location");
	if (location && location.startsWith(TARGET)) {
		responseHeaders.set(
			"location",
			proxyOrigin + location.substring(TARGET.length)
		);
	}

	const contentType = upstream.headers.get("content-type") ?? "";
	const isJson = contentType.includes("application/json");
	const isWellKnown =
		incoming.pathname.startsWith("/.well-known/oauth-") ||
		incoming.pathname.includes("/.well-known/oauth-");

	// For OAuth metadata responses (and any other JSON whose body references
	// the origin), rewrite the origin URL to the proxy URL so all endpoints
	// in the published metadata are reachable via this proxy. PHP's
	// json_encode escapes forward slashes by default, so the body contains
	// `https:\/\/ferramentasphda.pt` rather than the raw URL — handle both.
	if (isJson && (isWellKnown || incoming.pathname.includes("/oauth/") || incoming.pathname.includes("/mcp"))) {
		const text = await upstream.text();
		const escapedTarget = TARGET.replace(/\//g, "\\/");
		const escapedProxy = proxyOrigin.replace(/\//g, "\\/");
		const rewritten = text
			.replaceAll(escapedTarget, escapedProxy)
			.replaceAll(TARGET, proxyOrigin);
		responseHeaders.delete("content-length");
		return new Response(rewritten, {
			status: upstream.status,
			headers: responseHeaders,
		});
	}

	return new Response(upstream.body, {
		status: upstream.status,
		headers: responseHeaders,
	});
});
