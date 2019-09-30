const Encore = require('@symfony/webpack-encore')

Encore
  .addEntry('app', './views/assets/js/app.js')
  .addStyleEntry('style', './views/assets/css/style.scss')
  .cleanupOutputBeforeBuild()
  .disableSingleRuntimeChunk()
  .enableSassLoader()
  .enableSourceMaps(!Encore.isProduction())
  .setOutputPath('views/build/')
  .setPublicPath('/build')

const config = Encore.getWebpackConfig()

module.exports = config
