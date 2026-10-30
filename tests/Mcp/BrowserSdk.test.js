import { readFileSync } from "node:fs";
import { createContext, runInContext } from "node:vm";
import { describe, expect, it } from "vite-plus/test";

async function connectApp(filename, capabilities) {
    const sent = [];
    let listener;
    const parent = { postMessage: (message) => sent.push(message) };
    const context = createContext({
        window: {
            parent,
            addEventListener: (name, callback) => {
                listener = callback;
            },
        },
        document: { title: "Test App" },
    });

    runInContext(readFileSync(new URL(`../../src/mcp/resources/js/${filename}`, import.meta.url), "utf8"), context);

    const receive = (message) => listener({ source: parent, data: { jsonrpc: "2.0", ...message } });
    let app;
    const initialized = context.window.createMcpApp((value) => {
        app = value;
    }, capabilities);
    const initialize = sent.at(-1);
    receive({ id: initialize.id, result: {} });
    await initialized;
    sent.length = 0;

    return { app, receive, sent, initialize };
}

describe.each(["mcp-sdk.js", "mcp-sdk.min.js"])("%s", (filename) => {
    it.each([
        [undefined, {}],
        [
            { tools: {}, availableDisplayModes: ["inline", "fullscreen"] },
            { tools: {}, availableDisplayModes: ["inline", "fullscreen"] },
        ],
    ])("advertises the supplied app capabilities (%j)", async (capabilities, expected) => {
        const { initialize } = await connectApp(filename, capabilities);

        expect(initialize.params.appCapabilities).toEqual(expected);
    });

    it("handles a host request without settling an app request with the same ID", async () => {
        const { app, receive, sent } = await connectApp(filename);
        let settled = false;
        const pending = app.callServerTool("server-tool", {}).then((result) => {
            settled = true;
            return result;
        });
        const id = sent.at(-1).id;
        let receivedParams;
        const hostResult = { content: [{ type: "text", text: "App tool result" }] };
        app.onCallTool((params) => {
            receivedParams = params;
            return hostResult;
        });

        receive({ id, method: "tools/call", params: { name: "app-tool" } });
        await Promise.resolve();

        expect(receivedParams).toEqual({ name: "app-tool" });
        expect(sent.at(-1)).toEqual({ jsonrpc: "2.0", id, result: hostResult });
        expect(settled).toBe(false);

        const result = { content: [{ type: "text", text: "Server tool result" }] };
        receive({ id, result });
        await expect(pending).resolves.toEqual(result);
    });

    it("rejects the pending app request when its error response arrives", async () => {
        const { app, receive, sent } = await connectApp(filename);
        const pending = app.callServerTool("server-tool", {});
        const assertion = expect(pending).rejects.toThrow("Tool unavailable");

        receive({ id: sent.at(-1).id, error: { code: -32601, message: "Tool unavailable" } });

        await assertion;
    });

    it("does not respond to unmatched replies", async () => {
        const { receive, sent } = await connectApp(filename);

        receive({ id: 99, result: {} });
        receive({ id: 100, error: { code: -32601, message: "Unknown method" } });

        expect(sent).toEqual([]);
    });

    it.each([
        ["ui/resource-teardown", "onTeardown", {}],
        ["tools/list", "onListTools", { tools: [] }],
    ])("returns a result when an asynchronous %s handler returns nothing", async (method, register, result) => {
        const { app, receive, sent } = await connectApp(filename);
        let called = false;
        app[register](async () => {
            called = true;
        });

        receive({ id: 42, method });
        await new Promise(setImmediate);

        expect(called).toBe(true);
        expect(sent).toEqual([{ jsonrpc: "2.0", id: 42, result }]);
    });

    it("answers host pings", async () => {
        const { receive, sent } = await connectApp(filename);

        receive({ id: "host-ping", method: "ping" });

        expect(sent).toEqual([{ jsonrpc: "2.0", id: "host-ping", result: {} }]);
    });
});
