const webpack = require('webpack')
const dirConfig = require('./dir.config.js')
const entryConfig = require('./entry.config.js')

const config = {
  target: 'web',      //can be omitted as default is 'web'
  devtool: '#source-map',
  entry: entryConfig,

  output: {
    path: dirConfig.distDir,
    publicPath: '/dist/webpack',
    filename: '[name]'
  },

  module: {
    rules: [
      {
        test: /\.js$/,
        use: [
          {
            loader: 'babel-loader',
            options: {
              presets: ['env']
            },
          },
          {
            loader: 'eslint-loader',
            options: {
              configFile: `${dirConfig.rootDir}/eslint.config.js`
            }
          }
        ],
        exclude: /node_modules/
      },
      {
        test: /\.vue$/,
        use: [
          {
            loader: 'vue-loader'
          },
          {
            loader: 'eslint-loader',
            options: {
              configFile: `${dirConfig.rootDir}/eslint.config.js`
            }
          }
        ],
        exclude: /node_modules/
      },
      {
        test: /\.css$/,
        use: [
          {
            loader: "style-loader"
          },
          {
            loader: "css-loader",
            options: {
              modules: true
            }
          }
        ]
      },
    ],
  },

  resolve: {
    alias: {
      'vue$': 'vue/dist/vue.esm.js'
    }
  },

  devServer: {
    contentBase: "./",          //本地服务器所加载的页面所在的目录
    historyApiFallback: true,   //不跳转
    inline: true,               //实时刷新
    hot: true                   //HMR热加载
  },

  plugins: [
    new webpack.HotModuleReplacementPlugin(),    //热加载（Hot Module Replacement）插件
    new webpack.ProvidePlugin({
      Vue: ['vue/dist/vue.esm.js', 'default'],
      jQuery: 'jquery',
      'window.jQuery': 'jquery',
      $: 'jquery',
      moment: 'moment',
    })
  ],
}

module.exports = config
