import { execFileSync } from "node:child_process";
import { mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { afterEach, beforeEach, expect, test } from "vite-plus/test";

const generatorScript = path.join(__dirname, "generate.php");
let outputPath: string;

beforeEach(() => {
    outputPath = mkdtempSync(
        path.join(tmpdir(), "hypervel-wayfinder-app-url-"),
    );
});

afterEach(() => {
    rmSync(outputPath, { recursive: true, force: true });
});

const generateWithAppUrl = (
    appUrl: string,
    outputPath: string,
    env: NodeJS.ProcessEnv = {},
) => {
    execFileSync("php", [generatorScript, outputPath], {
        env: {
            ...process.env,
            APP_URL: appUrl,
            ...env,
        },
    });
};

test("prefixes generated URLs with APP_URL path", () => {
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

test("applies forced HTTPS scheme to explicit domain routes", () => {
    generateWithAppUrl("https://localhost:8001", outputPath, {
        WAYFINDER_FORCE_HTTPS: "1",
    });

    const contents = readFileSync(
        path.join(
            outputPath,
            "actions/Hypervel/Tests/Wayfinder/Fixtures/Controllers/DomainController.ts",
        ),
        "utf8",
    );

    expect(contents).toContain(
        "url: 'https://example.test/fixed-domain/{param}'",
    );
    expect(contents).toContain(
        "url: 'https://{defaultDomain?}.au/default-parameters-domain/{param}'",
    );
});
