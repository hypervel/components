import { expect, it } from "vitest";
import InvokablePlusController, {
    InvokablePlusControllerForm2,
} from "./.generated/actions/Hypervel/Tests/Wayfinder/Fixtures/Controllers/InvokablePlusController";

it("exports default and methods for invokable controllers", () => {
    expect(InvokablePlusController.url()).toBe("/invokable-plus-controller");
    expect(InvokablePlusController()).toEqual({
        url: "/invokable-plus-controller",
        method: "get",
    });

    expect(InvokablePlusController.store.url()).toBe(
        "/invokable-plus-controller",
    );
    expect(InvokablePlusController.store()).toEqual({
        url: "/invokable-plus-controller",
        method: "post",
    });

    expect(InvokablePlusControllerForm2.url()).toBe(
        "/invokable-plus-controller/form-name",
    );
    expect(InvokablePlusController.InvokablePlusControllerForm).toBe(
        InvokablePlusControllerForm2,
    );
});
