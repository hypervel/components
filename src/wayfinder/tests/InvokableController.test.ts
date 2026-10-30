import { expect, it } from "vite-plus/test";
import InvokableController from "./.generated/actions/Hypervel/Tests/Wayfinder/Fixtures/Controllers/InvokableController";

it("exports default for invokable controllers", () => {
    expect(InvokableController.url()).toBe("/invokable-controller");
    expect(InvokableController()).toEqual({
        url: "/invokable-controller",
        method: "get",
    });
});
