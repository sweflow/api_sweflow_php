--
-- PostgreSQL database dump
--

\restrict MC5g6zrHuUrXzd496j3ifrdcRg99ngaNjcPNgPpcvWzRFQZz37iobL33EJWE266

-- Dumped from database version 15.15
-- Dumped by pg_dump version 17.6

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: refresh_tokens; Type: TABLE; Schema: public; Owner: admin
--

CREATE TABLE public.refresh_tokens (
    jti uuid NOT NULL,
    user_uuid uuid NOT NULL,
    token_hash text NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    revoked boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.refresh_tokens OWNER TO admin;

--
-- Name: revoked_access_tokens; Type: TABLE; Schema: public; Owner: admin
--

CREATE TABLE public.revoked_access_tokens (
    jti uuid NOT NULL,
    user_uuid uuid NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


ALTER TABLE public.revoked_access_tokens OWNER TO admin;

--
-- Name: usuarios; Type: TABLE; Schema: public; Owner: admin
--

CREATE TABLE public.usuarios (
    uuid uuid NOT NULL,
    nome_completo character varying(255) NOT NULL,
    username character varying(50) NOT NULL,
    email character varying(255) NOT NULL,
    senha_hash character varying(255) NOT NULL,
    url_avatar character varying(255),
    url_capa character varying(255),
    biografia text,
    nivel_acesso character varying(20),
    token_recuperacao_senha character varying(255),
    token_verificacao_email character varying(255),
    ativo boolean DEFAULT true,
    verificado_email boolean DEFAULT false,
    criado_em timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    atualizado_em timestamp without time zone,
    status_verificacao character varying(30) DEFAULT 'Não verificado'::character varying,
    CONSTRAINT usuarios_nivel_acesso_check CHECK (((nivel_acesso)::text = ANY ((ARRAY['usuario'::character varying, 'admin'::character varying, 'moderador'::character varying, 'admin_system'::character varying])::text[])))
);


ALTER TABLE public.usuarios OWNER TO admin;

--
-- Name: refresh_tokens refresh_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: admin
--

ALTER TABLE ONLY public.refresh_tokens
    ADD CONSTRAINT refresh_tokens_pkey PRIMARY KEY (jti);


--
-- Name: revoked_access_tokens revoked_access_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: admin
--

ALTER TABLE ONLY public.revoked_access_tokens
    ADD CONSTRAINT revoked_access_tokens_pkey PRIMARY KEY (jti);


--
-- Name: usuarios usuarios_email_key; Type: CONSTRAINT; Schema: public; Owner: admin
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_email_key UNIQUE (email);


--
-- Name: usuarios usuarios_pkey; Type: CONSTRAINT; Schema: public; Owner: admin
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_pkey PRIMARY KEY (uuid);


--
-- Name: usuarios usuarios_username_key; Type: CONSTRAINT; Schema: public; Owner: admin
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_username_key UNIQUE (username);


--
-- Name: idx_refresh_tokens_expires; Type: INDEX; Schema: public; Owner: admin
--

CREATE INDEX idx_refresh_tokens_expires ON public.refresh_tokens USING btree (expires_at);


--
-- Name: idx_refresh_tokens_revoked; Type: INDEX; Schema: public; Owner: admin
--

CREATE INDEX idx_refresh_tokens_revoked ON public.refresh_tokens USING btree (revoked);


--
-- Name: idx_refresh_tokens_user; Type: INDEX; Schema: public; Owner: admin
--

CREATE INDEX idx_refresh_tokens_user ON public.refresh_tokens USING btree (user_uuid);


--
-- Name: idx_revoked_access_tokens_expires; Type: INDEX; Schema: public; Owner: admin
--

CREATE INDEX idx_revoked_access_tokens_expires ON public.revoked_access_tokens USING btree (expires_at);


--
-- Name: idx_revoked_access_tokens_user; Type: INDEX; Schema: public; Owner: admin
--

CREATE INDEX idx_revoked_access_tokens_user ON public.revoked_access_tokens USING btree (user_uuid);


--
-- PostgreSQL database dump complete
--

\unrestrict MC5g6zrHuUrXzd496j3ifrdcRg99ngaNjcPNgPpcvWzRFQZz37iobL33EJWE266

