import { defineProject } from "vite-plus";

// Vitest tests import from ./tests/.generated/ — TypeScript files that
// generate.php produces by running wayfinder:generate against the
// fixture routes at tests/Wayfinder/Fixtures/routes.php. The globalSetup
// regenerates that tree before each run.
export default defineProject({
    test: {
        name: "wayfinder",
        include: ["tests/**/*.test.ts"],
        environment: "happy-dom",
        globalSetup: "./tests/build.ts",
    },
});
