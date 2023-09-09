const Encore = require("@symfony/webpack-encore");
const ESLintPlugin = require("eslint-webpack-plugin");
const glob = require("glob");
const path = require("path");
const { PurgeCSSPlugin } = require("purgecss-webpack-plugin");

Encore.addEntry("app", "./views/assets/js/app.js")
    .addStyleEntry("style", "./views/assets/css/style.scss")
    .cleanupOutputBeforeBuild()
    .disableSingleRuntimeChunk()
    .enableSassLoader()
    .addPlugin(new ESLintPlugin())
    .enableSourceMaps(!Encore.isProduction())
    .setOutputPath("views/build/")
    .setPublicPath("/build")
    .addPlugin(
        new PurgeCSSPlugin({
            paths: glob.sync(`${path.join(__dirname, "views")}/**/*`, {
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

module.exports = config;
