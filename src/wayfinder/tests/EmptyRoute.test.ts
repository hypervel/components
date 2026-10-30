import { expect, it } from "vite-plus/test";
import { home } from "./.generated/routes";

it("doesn't add a / to an empty route", () => {
    expect(home.url()).toBe("/");
    expect(home()).toEqual({
        url: "/",
        method: "get",
    });
});
