import { readFileSync } from "node:fs";
import path from "node:path";
import { expect, test } from "vitest";
import { syntax } from "./.generated/routes/literal";

const scalar = "string | number | boolean";

test("preserves static route literals during generated syntax cleanup", () => {
    expect(syntax.url()).toBe("/literal/[ draft ]/(bar )/ .replace");
});

test("emits tuple optionals only for contiguous optional suffixes", () => {
    const optionalController = readFileSync(
        path.join(
            __dirname,
            ".generated/actions/Hypervel/Tests/Wayfinder/Fixtures/Controllers/OptionalController.ts",
        ),
        "utf8",
    );
    const urlDefaultsController = readFileSync(
        path.join(
            __dirname,
            ".generated/actions/Hypervel/Tests/Wayfinder/Fixtures/Controllers/UrlDefaultsController.ts",
        ),
        "utf8",
    );

    expect(optionalController).toContain(
        `[one?: ${scalar} | null, two?: ${scalar} | null, three?: ${scalar} | null]`,
    );
    expect(optionalController).toContain(
        `[required: ${scalar}, one?: ${scalar} | null, two?: ${scalar} | null]`,
    );
    expect(urlDefaultsController).toContain(
        `[locale: ${scalar} | null, timezone: ${scalar}]`,
    );
});

test("links generated methods to their original PHP actions", () => {
    const controller = readFileSync(
        path.join(
            __dirname,
            ".generated/actions/Hypervel/Tests/Wayfinder/Fixtures/Controllers/DisallowedMethodNameController.ts",
        ),
        "utf8",
    );
    const target =
        "\\Hypervel\\Tests\\Wayfinder\\Fixtures\\Controllers\\DisallowedMethodNameController";
    const annotations = controller.split(/\r?\n/);

    expect(controller).toContain("export const deleteMethod2");
    expect(annotations).toContain(`* @see ${target}::delete`);
    expect(annotations).toContain(`* @see ${target}::deleteMethod`);
    expect(annotations).not.toContain(`* @see ${target}::deleteMethod2`);
    expect(annotations).toContain(`* @see ${target}::404`);
    expect(annotations).toContain(`* @see ${target}::2fa`);
    expect(annotations).toContain(`* @see ${target}::default`);
});
