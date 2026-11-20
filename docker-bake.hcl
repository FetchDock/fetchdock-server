variable "TAGS" {
    type = list(string)
    default = ["latest"]
}

variable "IMAGES_PREFIX" {
    type = string
    #default = "ghcr.io/dunglas"
}

target "docker-metadata-action" {}

target "api" {
    inherits = ["docker-metadata-action"]
    tags = [for tag in TAGS: "${IMAGES_PREFIX}-api:${tag}"]
}

target "pwa" {
    inherits = ["docker-metadata-action"]
    tags = [for tag in TAGS: "${IMAGES_PREFIX}-pwa:${tag}"]
}

# Only build the images that are published. compose.yaml also defines a "worker" service that uses the
# api image name without a tag; as a bake target it would push "<prefix>-api:latest" on every release,
# including pre-releases.
group "default" {
    targets = ["api", "pwa"]
}
