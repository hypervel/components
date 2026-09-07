import { expect, test } from "vitest";
import {
    active,
    optional,
    price,
    reference,
    show,
} from "./.generated/actions/Hypervel/Tests/Wayfinder/Fixtures/Controllers/ModelBindingController";

test("will detect model binding", () => {
    expect(show.url(1)).toBe("/users/1");
    expect(show.url({ user: 1 })).toBe("/users/1");
    expect(show.url([1])).toBe("/users/1");
});

test("treats null as an omission without dereferencing bound parameters", () => {
    expect(show.url(null)).toBe("/users/42");
    expect(show.url({ user: null })).toBe("/users/42");
    expect(optional.url({ user: null })).toBe("/optional-users");

    expect(() => active.url(null as never)).toThrow(
        "Missing required route parameter: user.",
    );

    if (false) {
        // @ts-expect-error Required route parameters cannot be null.
        active.url(null);
        // @ts-expect-error A null binding key does not omit its route parameter.
        optional.url({ user: { id: null } });
    }
});

test("prefers casts before schema and PHPDoc binding evidence", () => {
    expect(price.url("12.50")).toBe("/users/price/12.50");

    if (false) {
        // @ts-expect-error Decimal casts are represented as strings.
        price.url(12.5);
    }
});

test("uses field-specific PHPDoc primitive unions when schema evidence is unavailable", () => {
    expect(active.url(true)).toBe("/users/active/1");
    expect(active.url({ user: { active: false } })).toBe("/users/active/0");
    expect(reference.url("external")).toBe("/users/reference/external");
    expect(reference.url(42)).toBe("/users/reference/42");
});

test("validates optional gaps after extracting model binding keys", () => {
    expect(() =>
        optional.url({
            user: {} as never,
            filter: "active",
        }),
    ).toThrow("Unexpected optional parameters missing");
});
