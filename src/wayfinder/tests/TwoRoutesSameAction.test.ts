import { expect, it } from "vitest";
import {
    matched,
    same,
    sameUri,
} from "./.generated/actions/Hypervel/Tests/Wayfinder/Fixtures/Controllers/TwoRoutesSameActionController";

it("creates a keyed dictionary of routes for multiple routes pointing to the same action", () => {
    expect(same["/two-routes-one-action-1"].url()).toBe(
        "/two-routes-one-action-1",
    );
    expect(same["/two-routes-one-action-1"]()).toEqual({
        url: "/two-routes-one-action-1",
        method: "get",
    });

    expect(same["/two-routes-one-action-2"].url()).toBe(
        "/two-routes-one-action-2",
    );
    expect(same["/two-routes-one-action-2"]()).toEqual({
        url: "/two-routes-one-action-2",
        method: "get",
    });
});

it("keys separately registered verbs for the same action and URI by verb", () => {
    const get = sameUri["get /two-routes-one-action-same-uri"];
    const post = sameUri["post /two-routes-one-action-same-uri"];

    expect(get.definition.methods).toEqual(["get", "head"]);
    expect(get()).toEqual({
        url: "/two-routes-one-action-same-uri",
        method: "get",
    });
    expect(post.definition.methods).toEqual(["post"]);
    expect(post()).toEqual({
        url: "/two-routes-one-action-same-uri",
        method: "post",
    });
});

it("preserves verb order for a single match route", () => {
    expect(matched.definition.methods).toEqual(["get", "post", "head"]);
});
