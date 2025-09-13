# PHP Download Router
## WIP - Do not use in production!

## Description

An API Platform powered API service which allows downloading files using various backends.

It is meant to be used as ingest service to centralize download requests to various platforms.
Instead of using multiple downloaders, you can just use this single service.

It routes download requests to the following backends based on their supported domains:
- yt-dlp (YouTube and many other video platforms)
- gallery-dl (Image hosting platforms)

More to be added in the future.

## Features
- Download files from various platforms using a single API
- Support for multiple backends (yt-dlp, gallery-dl)
- Easy to extend with additional backends
- Dockerized for easy deployment
- API documentation with Swagger UI


### Future Plans
- [ ] Add more backends for different platforms
- [ ] Implement authentication and authorization
- [ ] Add support for scheduling downloads
- [ ] Add support for monitoring URLS (periodic checks for new content)
- [ ] Implement retry mechanism for failed downloads
- [ ] Add support for different file formats and quality options
- [ ] Implement user management and quotas
- [ ] Add support for proxy servers
- [ ] Implement notifications (email, SMS, webhooks, etc.) for completed downloads
- [ ] Implement caching for frequently downloaded files
- [ ] Add monitoring and logging features (partially done)
- [ ] Implement rate limiting to prevent abuse
- [ ] Implement a frontend for easier interaction with the API


## Requirements
- Docker
- Docker Compose (recommended)
