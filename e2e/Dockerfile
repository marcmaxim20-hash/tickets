FROM mcr.microsoft.com/playwright:v1.52.0-noble

WORKDIR /opt/e2e
COPY package.json ./
RUN npm install --no-audit --no-fund

ENV NODE_PATH=/opt/e2e/node_modules
ENV PATH=/opt/e2e/node_modules/.bin:$PATH

WORKDIR /e2e
CMD ["playwright", "test"]
