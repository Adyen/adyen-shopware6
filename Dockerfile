# Define the image that will be used as a base image
ARG SHOPWARE_TAG=latest

FROM dockware/play:${SHOPWARE_TAG}

ARG RELEASE_TAG=latest

USER root
WORKDIR /var/www/html

# Install required tools
RUN apt-get update && apt-get install -y --no-install-recommends curl unzip

# Check if RELEASE_TAG is set
RUN echo "Using RELEASE_TAG=${RELEASE_TAG}" && \
    if [ -z "${RELEASE_TAG}" ]; then echo "RELEASE_TAG is not set!"; exit 1; fi

# Download the Adyen plugin
RUN curl --proto '=https' -f -L -o adyen-plugin.zip "https://github.com/Adyen/adyen-shopware6/releases/download/${RELEASE_TAG}/AdyenPaymentShopware6.zip"

# Extract the plugin and move it to the desired location
RUN unzip adyen-plugin.zip && mv AdyenPaymentShopware6 custom/plugins/AdyenPaymentShopware6

# Clean up temporary files
RUN rm adyen-plugin.zip && rm -rf /var/lib/apt/lists/*


USER dockware
