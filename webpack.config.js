import Encore from "@symfony/webpack-encore";

Encore.addEntry("app", "./views/assets/js/app.js")
    .addStyleEntry("style", "./views/assets/css/style.scss")
    .cleanupOutputBeforeBuild()
    .disableSingleRuntimeChunk()
    .enableSassLoader()
    .enableSourceMaps(!Encore.isProduction())
    .setOutputPath("views/build/")
    .setPublicPath("/build")
;

const config = Encore.getWebpackConfig();

// Set IE11-friendly defaults
// https://webpack.js.org/configuration/output/#outputenvironment
config.output.environment = config.output.environment || {};
config.output.environment.arrowFunction = false;
config.output.environment.const = false;
config.output.environment.destructuring = false;

export { config as default }
