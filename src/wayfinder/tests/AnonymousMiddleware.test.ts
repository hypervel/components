import { expect, test } from "vitest";
import { show } from "./.generated/actions/Hypervel/Tests/Wayfinder/Fixtures/Controllers/AnonymousMiddlewareController";

test("will allow for closure middleware", () => {
    expect(show.url()).toBe("/anonymous-middleware");
});
