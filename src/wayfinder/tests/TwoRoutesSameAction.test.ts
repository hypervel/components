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

it("coalesces separately registered verbs for the same action and URI", () => {
    const route = sameUri["/two-routes-one-action-same-uri"];

    expect(route.definition.methods).toEqual(["get", "head", "post"]);
    expect(route()).toEqual({
        url: "/two-routes-one-action-same-uri",
        method: "get",
    });
    expect(route.post()).toEqual({
        url: "/two-routes-one-action-same-uri",
        method: "post",
    });
});

it("preserves verb order for a single match route", () => {
    expect(matched.definition.methods).toEqual(["get", "post", "head"]);
});
