import { describe, expect, test } from "vitest";
import {
    mixedDefaults,
    onlyDefaults,
    parsedDefaults,
} from "./.generated/actions/Hypervel/Tests/Wayfinder/Fixtures/Controllers/UrlDefaultsController";
import { setUrlDefaults } from "./.generated/wayfinder";

describe("onlyDefault", async () => {
    test("url", () => {
        expect(onlyDefaults.url()).toBe("/with-defaults/en");
        expect(onlyDefaults.url({ locale: "es" })).toBe("/with-defaults/es");
    });

    test("default method", () => {
        expect(onlyDefaults()).toEqual({
            url: "/with-defaults/en",
            method: "post",
        });

        expect(onlyDefaults({ locale: "es" })).toEqual({
            url: "/with-defaults/es",
            method: "post",
        });
    });
});

describe("mixedDefault", async () => {
    test("url", () => {
        expect(mixedDefaults.url({ timezone: "UTC" })).toBe(
            "/with-defaults/en/also/UTC",
        );
        expect(mixedDefaults.url({ timezone: "UTC", locale: "es" })).toBe(
            "/with-defaults/es/also/UTC",
        );
    });

    test("default method", () => {
        expect(mixedDefaults({ timezone: "UTC" })).toEqual({
            url: "/with-defaults/en/also/UTC",
            method: "post",
        });

        expect(mixedDefaults({ timezone: "UTC", locale: "es" })).toEqual({
            url: "/with-defaults/es/also/UTC",
            method: "post",
        });
    });
});

describe("parsedDefaults", () => {
    test("parses every supported defaults call without harvesting neighboring arrays", () => {
        setUrlDefaults({ dynamic: "UTC", computed: "runtime" });

        try {
            expect(
                parsedDefaults.url({
                    literalNull: "literal-null",
                    unsupported: "unsupported-array",
                    neighbor: "neighbor",
                }),
            ).toBe(
                "/parsed-defaults/en/-12/1.5/1/0/10/10/1000/10/-10/10/16/2/10/UTC/second/runtime/literal-null/unsupported-array/neighbor",
            );

            expect(() =>
                // @ts-expect-error Intentionally omit the neighboring-array decoy parameter.
                parsedDefaults.url({
                    literalNull: "literal-null",
                    unsupported: "unsupported-array",
                }),
            ).toThrow("Missing required route parameter: neighbor.");

            // @ts-expect-error Intentionally bypass the generated type to verify runtime validation.
            expect(() => parsedDefaults.url()).toThrow(
                "Missing required route parameter: literalNull.",
            );
        } finally {
            setUrlDefaults({});
        }
    });

    test("requires default-backed parameters to resolve before rendering", () => {
        setUrlDefaults({ dynamic: "UTC" });

        try {
            expect(() =>
                parsedDefaults.url({
                    literalNull: "literal-null",
                    unsupported: "unsupported-array",
                    neighbor: "neighbor",
                }),
            ).toThrow("Missing required route parameter: computed.");

            setUrlDefaults({ dynamic: "UTC", computed: "runtime" });

            expect(() =>
                parsedDefaults.url({
                    locale: "",
                    literalNull: "literal-null",
                    unsupported: "unsupported-array",
                    neighbor: "neighbor",
                }),
            ).toThrow("Missing required route parameter: locale.");
        } finally {
            setUrlDefaults({});
        }
    });
});
