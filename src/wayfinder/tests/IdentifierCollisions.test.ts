import { readFileSync } from "node:fs";
import path from "node:path";
import { expect, test } from "vitest";
import Controllers from "./.generated/actions/Hypervel/Tests/Wayfinder/Fixtures/Controllers";
import barrelAlias from "./.generated/routes/barrel-alias";
import collision from "./.generated/routes/collision";
import formShadow from "./.generated/routes/form-shadow";
import mixedForm, {
    showForm as mixedShowForm,
} from "./.generated/routes/mixed-form";
import namedReverse from "./.generated/routes/named-reverse";
import nested from "./.generated/routes/nested";
import numericKey from "./.generated/routes/numeric-key";
import reports from "./.generated/routes/reports";
import reverseFormShadow from "./.generated/routes/reverse-form-shadow";
import reverseMixedForm, {
    showForm as reverseMixedShowForm,
} from "./.generated/routes/reverse-mixed-form";

const generated = path.join(__dirname, ".generated");

test("preserves controller leaves and child namespaces in either registration order", () => {
    expect(Controllers.BarrelCollisionController.show.url()).toBe(
        "/barrel-collision",
    );
    expect(
        Controllers.BarrelCollisionController.NestedController.index.url(),
    ).toBe("/barrel-collision/nested");

    expect(Controllers.ReverseBarrelCollisionController.show.url()).toBe(
        "/reverse-barrel-collision",
    );
    expect(
        Controllers.ReverseBarrelCollisionController.NestedController.index.url(),
    ).toBe("/reverse-barrel-collision/nested");
});

test("preserves named route leaves and child namespaces in either registration order", () => {
    expect(nested.child.url()).toBe("/nested/controller/child");
    expect(nested.child.grandchild.url()).toBe(
        "/nested/controller/child/grandchild",
    );
    expect(namedReverse.child.url()).toBe("/named-reverse/child");
    expect(namedReverse.child.grandchild.url()).toBe(
        "/named-reverse/child/grandchild",
    );
    expect(nested["foo-bar"].index.url()).toBe("/nested/foo-bar");
    expect(nested.fooBar.index.url()).toBe("/nested/foo-bar-camel");
    expect(reports.index.daily.url()).toBe("/reports/index/daily");
});

test("keeps public keys independent of internal declaration names", () => {
    expect(collision["foo-bar"].url()).toBe("/named-collision/foo-bar");
    expect(collision.fooBar.url()).toBe("/named-collision/foo-bar-camel");
    expect(collision.fooBar2.url()).toBe("/named-collision/foo-bar-two");
    expect(numericKey["1e3"].url()).toBe("/named-collision/numeric-key");
});

test("reserves form helper names only for declarations that create them", () => {
    expect(mixedForm.show.child.url()).toBe("/mixed-form/show/child");
    expect(mixedForm.showForm).toBe(mixedShowForm);
    expect(reverseMixedForm.show.child.url()).toBe(
        "/reverse-mixed-form/show/child",
    );
    expect(reverseMixedForm.showForm).toBe(reverseMixedShowForm);

    expect(formShadow.edit.url()).toBe("/form-shadow/edit");
    expect(formShadow.editForm.child.url()).toBe(
        "/form-shadow/edit-form/child",
    );
    expect(reverseFormShadow.edit.url()).toBe("/reverse-form-shadow/edit");
    expect(reverseFormShadow.editForm.child.url()).toBe(
        "/reverse-form-shadow/edit-form/child",
    );

    expect(barrelAlias.Foo.child.url()).toBe("/barrel-alias/foo/child");
    expect(barrelAlias.FooForm.child.url()).toBe(
        "/barrel-alias/foo-form/child",
    );
});

test("uses explicit namespace imports without no-op object assignment", () => {
    const controllers = readFileSync(
        `${generated}/actions/Hypervel/Tests/Wayfinder/Fixtures/Controllers/index.ts`,
        "utf8",
    );
    const reportRoutes = readFileSync(
        `${generated}/routes/reports/index.ts`,
        "utf8",
    );
    const apiRoutes = readFileSync(
        `${generated}/routes/api/v1/index.ts`,
        "utf8",
    );

    expect(controllers).toContain(
        'from "./BarrelCollisionController/index"',
    );
    expect(controllers).toContain(
        'from "./ReverseBarrelCollisionController/index"',
    );
    expect(controllers).not.toContain(
        "Object.assign(DomainController, DomainController)",
    );
    expect(reportRoutes).toContain('from "./index/index"');
    expect(apiRoutes).toContain("    taskStatus,");
    expect(apiRoutes).not.toContain("'task-status': taskStatus");
});
