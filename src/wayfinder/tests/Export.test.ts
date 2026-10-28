import { expect, test } from "vite-plus/test";
import { exportMethod } from "./.generated/routes/index";

test("export param", () => {
    expect(exportMethod.url({ report: "a", export: "b" })).toBe("/export/a/b");
    expect(exportMethod.url(["a", "b"])).toBe("/export/a/b");
});
