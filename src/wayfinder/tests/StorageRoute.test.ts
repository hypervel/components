import { expect, it } from "vitest";
import storage from "./.generated/routes/storage";

it("can import storage routes", () => {
    expect(storage.export("file-name")).toEqual({
        url: "/storage/file-name",
        method: "get",
    });
});
