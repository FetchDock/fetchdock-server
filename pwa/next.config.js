/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  output: 'standalone',
  // The build stage installs devDependencies (see pwa/Dockerfile). typescript is only needed to build,
  // but would otherwise be traced into the standalone output (~22 MB).
  outputFileTracingExcludes: {
    '*': ['node_modules/typescript/**', 'node_modules/.pnpm/typescript@*/**'],
  },
  images: {
    // Disables the Image Optimization API (/_next/image), the vector of critical Next.js RCE advisories
    // affecting the pinned next version (see #66). The pwa only uses next/image for static SVG logos.
    unoptimized: true,
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
