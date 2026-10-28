import { expect, it } from "vite-plus/test";
import nested from "./.generated/routes/nested";

it("can handle conflicting nested route names", () => {
    expect(nested.child().url).toBe("/nested/controller/child");
    expect(nested.child.grandchild().url).toBe(
        "/nested/controller/child/grandchild",
    );
});
