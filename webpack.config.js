import Encore from "@symfony/webpack-encore";
import { PurgeCSSPlugin } from "purgecss-webpack-plugin";
import { glob } from "glob";

Encore.addEntry("app", "./views/assets/js/app.js")
    .addStyleEntry("style", "./views/assets/css/style.scss")
    .cleanupOutputBeforeBuild()
    .disableSingleRuntimeChunk()
    .enableSassLoader()
    .enableSourceMaps(!Encore.isProduction())
    .setOutputPath("views/build/")
    .setPublicPath("/build")
    .addPlugin(
        new PurgeCSSPlugin({
            paths: () =>
                glob.sync([`views/*.html.twig`, `views/assets/js/**/*.js`], {
                    nodir: true,
                }),
        }),
    );

const config = Encore.getWebpackConfig();

// Set IE11-friendly defaults
// https://webpack.js.org/configuration/output/#outputenvironment
config.output.environment = config.output.environment || {};
config.output.environment.arrowFunction = false;
config.output.environment.const = false;
config.output.environment.destructuring = false;

export { config as default };
