const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const DependencyExtractionWebpackPlugin = require('@woocommerce/dependency-extraction-webpack-plugin');
const {resolve} = require('path');

module.exports = {
    ...defaultConfig,
    output: {
        filename: '[name].js',
        path: resolve(process.cwd(), 'ressources/build'),
    },
    module: {
        rules: [
            {
                test: /\.tsx?$/,
                exclude: /node_modules/,
                loader: 'ts-loader',
            },
            ...defaultConfig.module.rules,
        ],
    },
    plugins: [
        ...defaultConfig.plugins.filter(
            (plugin) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin',
        ),
        new DependencyExtractionWebpackPlugin(),
    ],
    resolve: {
        extensions: ['.js', '.jsx', '.ts', '.tsx'],
    },
    entry: {
        blocks: './client/blocks/index.js',
    },
};
