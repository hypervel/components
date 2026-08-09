import { expect, it, test } from "vitest";
import {
    edit,
    external,
    show,
} from "./.generated/actions/Hypervel/Tests/Wayfinder/Fixtures/Controllers/KeyController";

it("can pass primitive values to routes with custom keys", () => {
    expect(edit.url({ key: "547a7452-9dc5-4f64-a275-d646dea6ebcf" })).toBe(
        "/keys/547a7452-9dc5-4f64-a275-d646dea6ebcf/edit",
    );
});

it("can pass objects with custom key", () => {
    const key = { uuid: "547a7452-9dc5-4f64-a275-d646dea6ebcf" };

    expect(edit.url({ key })).toBe(
        "/keys/547a7452-9dc5-4f64-a275-d646dea6ebcf/edit",
    );
});

test("route parameters use backend-equivalent encoding", () => {
    expect(show.url("hello world/ünicode'()")).toBe(
        "/keys/hello%20world/%C3%BCnicode%27%28%29",
    );
    expect(show.url("@foo:bar;,=+!|?&#%*")).toBe(
        "/keys/@foo:bar;,=+!|?&#%*",
    );
});

test("route parameters preserve JavaScript replacement-token values", () => {
    expect(show.url("$&")).toBe("/keys/%24&");
    expect(show.url("$`")).toBe("/keys/%24%60");
    expect(show.url("$'")).toBe("/keys/%24%27");
    expect(show.url("$$")).toBe("/keys/%24%24");
});

test("route parameters accept booleans through every argument shape", () => {
    expect(show.url(true)).toBe("/keys/1");
    expect(show.url({ key: false })).toBe("/keys/0");
    expect(show.url([true])).toBe("/keys/1");
});

test("required route parameters reject empty values by name", () => {
    expect(() => show.url({ key: "" })).toThrow(
        "Missing required route parameter: key.",
    );
});

test("model binding fields may use non-identifier property names", () => {
    expect(external.url({ key: { "external-id": "value" } })).toBe(
        "/keys/value/external",
    );
    expect(external.url({ key: "value" })).toBe("/keys/value/external");
});

test("definition", () => {
    expect(Object.keys(edit.definition)).toEqual(["methods", "url"]);
    expect(edit.definition.methods).toEqual(["get", "head"]);
    expect(edit.definition.url).toBe("/keys/{key}/edit");
});
