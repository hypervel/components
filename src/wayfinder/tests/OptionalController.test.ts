import { describe, expect, it, test } from "vitest";
import {
    manyOptional,
    optional,
    requiredWithOptional,
} from "./.generated/actions/Hypervel/Tests/Wayfinder/Fixtures/Controllers/OptionalController";

describe("optional", async () => {
    test("url", () => {
        expect(optional.url()).toBe("/optional");
        expect(optional.url({ parameter: "xxxx" })).toBe("/optional/xxxx");
    });

    test("definition", () => {
        expect(optional.definition.url).toBe("/optional/{parameter?}");
    });
});

describe("manyOptional", async () => {
    test("url", () => {
        expect(manyOptional.url()).toBe("/many-optional");
        expect(manyOptional.url({ one: "1" })).toBe("/many-optional/1");
        expect(manyOptional.url({ one: "1", two: "2" })).toBe(
            "/many-optional/1/2",
        );
        expect(manyOptional.url({ one: "1", two: "2", three: "3" })).toBe(
            "/many-optional/1/2/3",
        );
    });

    test("url supports falsy optional values", () => {
        expect(manyOptional.url({ one: 0, two: 2 })).toBe("/many-optional/0/2");
        expect(manyOptional.url({ one: 0 })).toBe("/many-optional/0");
        expect(manyOptional.url({ one: false, two: true })).toBe(
            "/many-optional/0/1",
        );
    });

    test("url supports null omission values", () => {
        expect(optional.url(null)).toBe("/optional");
        expect(optional.url({ parameter: null })).toBe("/optional");
        expect(optional.url([null])).toBe("/optional");
        expect(manyOptional.url({ one: "1", two: null })).toBe(
            "/many-optional/1",
        );
        expect(manyOptional.url(["1", null])).toBe("/many-optional/1");
        expect(requiredWithOptional.url(["required", null])).toBe(
            "/required-with-optional/required",
        );
    });

    it("throws an error when passing optional parameters with missing optional parameters before", () => {
        expect(() => manyOptional.url({ two: "2" })).toThrow();
        expect(() => manyOptional.url({ three: "3" })).toThrow();
        expect(() => manyOptional.url({ two: "2", three: "3" })).toThrow();
    });

    test("definition", () => {
        expect(manyOptional.definition.url).toBe(
            "/many-optional/{one?}/{two?}/{three?}",
        );
    });
});

describe("requiredWithOptional", () => {
    test("url", () => {
        expect(requiredWithOptional.url(["required"])).toBe(
            "/required-with-optional/required",
        );
        expect(requiredWithOptional.url(["required", "one"])).toBe(
            "/required-with-optional/required/one",
        );
    });
});
