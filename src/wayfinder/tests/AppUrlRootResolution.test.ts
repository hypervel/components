import { execFileSync } from "node:child_process";
import { readFileSync, rmSync } from "node:fs";
import path from "node:path";
import { expect, test } from "vitest";

const generatorScript = path.join(__dirname, "generate.php");

const generateWithAppUrl = (appUrl: string, outputPath: string) => {
    rmSync(outputPath, { recursive: true, force: true });

    execFileSync("php", [generatorScript, outputPath], {
        env: {
            ...process.env,
            APP_URL: appUrl,
        },
    });
};

test("prefixes generated URLs with APP_URL path", () => {
    const outputPath = "/tmp/wayfinder-app-url-path";

    generateWithAppUrl("http://localhost:8081/v2", outputPath);

    const contents = readFileSync(
        path.join(
            outputPath,
            "actions/Hypervel/Tests/Wayfinder/Fixtures/Controllers/PostController.ts",
        ),
        "utf8",
    );

    expect(contents).toContain("url: '/v2/posts'");
    expect(contents).toContain("url: '/v2/posts/{post}'");
});

test("does not inject APP_URL port into explicit domain routes", () => {
    const outputPath = "/tmp/wayfinder-app-url-port";

    generateWithAppUrl("https://localhost:8001", outputPath);

    const contents = readFileSync(
        path.join(
            outputPath,
            "actions/Hypervel/Tests/Wayfinder/Fixtures/Controllers/DomainController.ts",
        ),
        "utf8",
    );

    expect(contents).toContain("url: '//example.test/fixed-domain/{param}'");
    expect(contents).toContain(
        "url: '//{defaultDomain?}.au/default-parameters-domain/{param}'",
    );
});
