import Head from "next/head";
import Image from "next/image";
import Link from "next/link";
import React from "react";
import adminPicture from "../public/api-platform/admin.svg";
import apiPlatformLogo from "../public/api-platform/logo_api-platform.svg";
import mercurePicture from "../public/api-platform/mercure.svg";
import apiPicture from "../public/api-platform/api.svg";
import "@fontsource/poppins";
import "@fontsource/poppins/600.css";
import "@fontsource/poppins/700.css";

const Welcome = () => (
  <div className="w-full overflow-x-hidden">
    <Head>
      <title>Welcome to FetchDock!</title>
    </Head>
    <section className="w-full bg-spider-cover relative">
      <a
        href="https://les-tilleuls.coop/en"
        target="_blank"
        rel="noreferrer noopener"
        className="z-10 bg-black px-8 py-2 text-xs text-white ribbon | md:px-12"
      >
        <div className="flex flex-row justify-center items-center translate-x-[5%]">
          Powered by
          <div className="pl-2 flex items-center">
            <Image
              alt="Les-Tilleuls.coop"
              src={apiPlatformLogo}
              width={100}
              height={20}
            />
          </div>
        </div>
      </a>
      <div className="container flex flex-row pt-24 pb-8 | md:px-20">
        <div className="flex flex-1 flex-col items-center text-center | md:text-left md:items-start">
          <h1>
            <span className="block text-4xl text-cyan-200 font-bold mb-2">
              Welcome to FetchDock!
            </span>
          </h1>
          <p className="text-cyan-200 my-5 text-lg">
            Centralize and keep track of your downloads!
          </p>
        </div>
      </div>
    </section>
    <section className="bg-white py-8">
      <div className="container | md:px-20">
        <div className="text-center | lg:text-left lg:w-3/5 lg:ml-auto">
          <h2 className="text-black text-md font-bold mb-5">
            Available services:
          </h2>
          <div className="flex justify-center flex-wrap | lg:justify-start lg:grid lg:gap-5 lg:grid-cols-2">
            <Card image={apiPicture} title="API" url="/docs" />
            <Card image={adminPicture} title="Admin" url="/admin" />
            <Card
              image={mercurePicture}
              title="Mercure debugger"
              url="/.well-known/mercure/ui/"
            />
          </div>
        </div>
      </div>
    </section>
  </div>
);
export default Welcome;

const Card = ({
  image,
  url,
  title,
}: {
  image: string;
  url: string;
  title: string;
}) => (
  <div className="w-full max-w-xs p-2 | sm:w-1/2 | lg:w-full lg:p-0">
  <a
    href={url}
    className="w-full flex items-center flex-col justify-center shadow-card p-3 min-h-24 transition-colors text-cyan-500 border-4 border-transparent hover:border-cyan-200 hover:text-cyan-700 | sm:flex-row sm:justify-start sm:px-5"
  >
    <Image src={image} width="50" height="50" alt="" />
    <h3 className="text-center text-base uppercase font-semibold leading-tight pt-3 | sm:text-left sm:pt-0 sm:pl-5">
      {title}
    </h3>
  </a>
  </div>
);

const HelpButton = ({
  children,
  url,
  title,
}: {
  url: string;
  title: string;
  children: React.ReactNode;
}) => (
  (<Link
    href={url}
    target="_blank"
    rel="noopener noreferrer"
    className="w-12 h-12 p-2.5 rounded-full border-2 border-gray-100 justify-center transition-colors hover:border-cyan-200 hover:bg-cyan-200/50 m-2 inline-flex items-center | md:p-1 md:w-9 md:h-9 md:flex md:mx-auto md:m-0"
    title={title}>

    {children}

  </Link>)
);
