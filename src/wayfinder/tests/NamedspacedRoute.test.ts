import { expect, it } from "vite-plus/test";
import { store } from "./.generated/routes/namespaced/my-package";

it("can access a namespaced route", () => {
    expect(store.url()).toBe("/package-route");
    expect(store()).toEqual({
        url: "/package-route",
        method: "get",
    });
});
