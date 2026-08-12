import { expect, test } from "vitest";
import DisallowedMethodNameController, {
    applyUrlDefaults2,
    argumentsMethod,
    deleteMethod,
    deleteMethod2,
    DisallowedMethodNameController2,
    DisallowedMethodNameControllerForm,
    evalMethod,
    formatRouteParameter2,
    method404,
    queryParams2,
    show,
    showForm2,
    validateParameters2,
} from "./.generated/actions/Hypervel/Tests/Wayfinder/Fixtures/Controllers/DisallowedMethodNameController";
import method2fa from "./.generated/routes/2fa";
import defaultMethod from "./.generated/routes/default";
import disallowed from "./.generated/routes/disallowed";

test("will append `method` to invalid methods", () => {
    expect(method404.url()).toBe("/disallowed/404");
    expect(deleteMethod.url()).toBe("/disallowed/delete");
    expect(DisallowedMethodNameController.delete.url()).toBe(
        "/disallowed/delete",
    );
    expect(DisallowedMethodNameController[404].url()).toBe("/disallowed/404");
});

test("will preserve numeric named route keys", () => {
    expect(disallowed[404].url()).toBe("/disallowed/404");
});

test("will properly handle leading numbers", () => {
    expect(method2fa.disallowed.url()).toBe("/disallowed/2fa");
    expect(DisallowedMethodNameController["2fa"].url()).toBe("/disallowed/2fa");
});

test("will properly handle reserved JS words", () => {
    expect(defaultMethod.login.url()).toBe("/disallowed/default");
    expect(DisallowedMethodNameController["default"].url()).toBe(
        "/disallowed/default",
    );
});

test("allocates controller names around runtime, suffix, form, and default collisions", () => {
    expect(deleteMethod.url()).toBe("/disallowed/delete");
    expect(deleteMethod2.url()).toBe("/disallowed/delete-method");
    expect(queryParams2.url()).toBe("/disallowed/query-params");
    expect(applyUrlDefaults2.url()).toBe("/disallowed/apply-url-defaults");
    expect(validateParameters2.url()).toBe("/disallowed/validate-parameters");
    expect(formatRouteParameter2.url()).toBe(
        "/disallowed/format-route-parameter",
    );
    expect(show.url()).toBe("/disallowed/show");
    expect(show.form()).toEqual({
        action: "/disallowed/show",
        method: "get",
    });
    expect(showForm2.url()).toBe("/disallowed/show-form");
    expect(evalMethod.url()).toBe("/disallowed/eval");
    expect(argumentsMethod.url()).toBe("/disallowed/arguments");
    expect(DisallowedMethodNameController2.url()).toBe(
        "/disallowed/controller-name",
    );
    expect(DisallowedMethodNameControllerForm.url()).toBe(
        "/disallowed/controller-form-name",
    );

    expect(DisallowedMethodNameController.delete).toBe(deleteMethod);
    expect(DisallowedMethodNameController.deleteMethod).toBe(deleteMethod2);
    expect(DisallowedMethodNameController.showForm).toBe(showForm2);
    expect(DisallowedMethodNameController.DisallowedMethodNameController).toBe(
        DisallowedMethodNameController2,
    );
});
