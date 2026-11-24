FROM node:22.11.0-alpine3.20 AS build
WORKDIR /src
RUN npm install -g typescript@5.6.3 && npm install --prefix /deps qrcode-generator@1.4.4
COPY tsconfig.json .
COPY src ./src
RUN tsc -p tsconfig.json && cp /deps/node_modules/qrcode-generator/qrcode.js dist/qrcode.js

FROM nginx:1.27.3-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY public /usr/share/nginx/html
COPY --from=build /src/dist/ /usr/share/nginx/html/
