FROM mcr.microsoft.com/playwright:v1.65.1-noble
RUN apt-get update && apt-get install -y --no-install-recommends libnss3-tools ca-certificates \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /browser
RUN npm init -y && npm install --ignore-scripts --save-exact playwright@1.65.1
ENV NODE_PATH=/browser/node_modules
ENTRYPOINT ["bash", "/app/conformance/deployment/browser-entrypoint.sh"]
