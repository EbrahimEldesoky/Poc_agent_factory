FROM node:22.11.0-alpine3.20 AS build
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci
COPY tsconfig.json .
COPY src ./src
RUN npx tsc -p tsconfig.json && cp node_modules/qrcode-generator/qrcode.js dist/qrcode.js

FROM nginx:1.27.3-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY public /usr/share/nginx/html
COPY --from=build /src/dist/ /usr/share/nginx/html/
