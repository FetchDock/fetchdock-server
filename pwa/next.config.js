/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  output: 'standalone',
  // The build stage installs devDependencies (see pwa/Dockerfile). typescript is only needed to build,
  // but would otherwise be traced into the standalone output (~22 MB).
  outputFileTracingExcludes: {
    '*': ['node_modules/typescript/**', 'node_modules/.pnpm/typescript@*/**'],
  },
  webpack: (config, { isServer }) => {
    if (!isServer) {
      config.resolve.fallback = {
        ...config.resolve.fallback,
        fs: false,
      }
    }

    return config
  }
};

module.exports = nextConfig
