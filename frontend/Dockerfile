# frontend/Dockerfile
FROM node:20-alpine AS builder

WORKDIR /app

# Install dependencies (cached)
# COPY package*.json ./
# RUN npm config set registry https://registry.npmmirror.com
# RUN npm install

# Copy source
# COPY . .

# Build (will be overridden in dev mode)
# CMD ["npm", "run", "dev", "--", "--host"]

# For now, just keep the container running so we can exec into it
CMD ["tail", "-f", "/dev/null"]
